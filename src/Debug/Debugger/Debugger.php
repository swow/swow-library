<?php
/**
 * This file is part of Swow
 *
 * @link    https://github.com/swow/swow
 * @contact twosee <twosee@php.net>
 *
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code
 */

declare(strict_types=1);

namespace Swow\Debug\Debugger;

use ReflectionObject;
use SplFileObject;
use Swow\Coroutine;
use Throwable;

use function array_filter;
use function array_shift;
use function count;
use function explode;
use function func_get_args;
use function strtolower;
use function trim;

use const PHP_INT_MAX;

class Debugger
{
    public const SDB = <<<'TEXT'
  ██████ ▓█████▄  ▄▄▄▄
▒██    ▒ ▒██▀ ██▌▓█████▄
░ ▓██▄   ░██   █▌▒██▒ ▄██
  ▒   ██▒░▓█▄   ▌▒██░█▀
▒██████▒▒░▒████▓ ░▓█  ▀█▓
 ░▒▓▒ ▒ ░ ▒▒▓  ▒ ░▒▓███▀▒
  ░▒      ░ ▒  ▒ ▒░▒   ░
-------------------
SDB (Swow Debugger)
-------------------
TEXT;

    /** @var static */
    protected static self $instance;

    protected ReflectionObject $reflection;

    protected bool $daemon = true;

    protected bool $reloading = false;

    protected string $lastCommand = '';

    protected Coroutine $currentCoroutine;

    protected int $currentFrameIndex = 0;

    protected ?SplFileObject $currentSourceFile = null;

    protected int $currentSourceFileLine = 0;

    /**  @var int For "next" command, only break when the trace depth
     * is less or equal to the last trace depth */
    protected int $lastTraceDepth = PHP_INT_MAX;

    use DebuggerBreakpointTrait;

    use DebuggerCommandTrait;

    use DebuggerDebugContextTrait;

    use DebuggerIoTrait;

    use DebuggerSourceMapTrait;

    use DebuggerStaticGetterTrait;

    final protected static function getInstance(): static
    {
        /* @phpstan-ignore-next-line */
        return self::$instance ?? (self::$instance = new static());
    }

    protected function __construct()
    {
        $this->reflection = new ReflectionObject($this);
        $this->__constructDebuggerIo();
        $this->__constructDebuggerSourceMap();
        $this->setCurrentCoroutine(Coroutine::getCurrent());
    }

    public function __destruct()
    {
        $this->__destructDebuggerBreakpoint();
    }

    public function getLastCommand(): string
    {
        return $this->lastCommand;
    }

    public function setLastCommand(string $lastCommand): static
    {
        $this->lastCommand = $lastCommand;

        return $this;
    }

    public function getCurrentCoroutine(): Coroutine
    {
        return $this->currentCoroutine;
    }

    protected function setCurrentCoroutine(Coroutine $coroutine): static
    {
        $this->currentCoroutine = $coroutine;
        $this->currentFrameIndex = 0;

        return $this;
    }

    /**
     * @return array<int, array{
     *     'function': string|null,
     *     'class': string|null,
     *     'args': array<string>,
     *     'file': string|null,
     *     'line': int|null,
     *     'type': string|null,
     * }> $trace
     */
    protected function getCurrentCoroutineTrace(): array
    {
        return static::getTraceOfCoroutine($this->getCurrentCoroutine());
    }

    public function getCurrentFrameIndex(): int
    {
        return $this->currentFrameIndex;
    }

    public function getCurrentFrameIndexExtendedForExecution(): int
    {
        return $this->currentFrameIndex + static::getExtendedLevelOfCoroutineForExecution($this->getCurrentCoroutine());
    }

    protected function setCurrentFrameIndex(int $index): static
    {
        if (count($this->getCurrentCoroutineTrace()) < $index) {
            throw new DebuggerException('Invalid frame index');
        }
        $this->currentFrameIndex = $index;

        return $this;
    }

    public function getCurrentSourceFile(): ?SplFileObject
    {
        return $this->currentSourceFile;
    }

    public function setCurrentSourceFile(?SplFileObject $currentSourceFile): static
    {
        $this->currentSourceFile = $currentSourceFile;

        return $this;
    }

    public function getCurrentSourceFileLine(): int
    {
        return $this->currentSourceFileLine;
    }

    public function setCurrentSourceFileLine(int $currentSourceFileLine): static
    {
        $this->currentSourceFileLine = $currentSourceFileLine;

        return $this;
    }

    /**
     * @param array<int, array{
     *     'function': string|null,
     *     'class': string|null,
     *     'args': array<string>,
     *     'file': string|null,
     *     'line': int|null,
     * }> $trace
     */
    protected function showTrace(array $trace, ?int $frameIndex = null, bool $newLine = true): static
    {
        $traceTable = DebuggerHelper::convertTraceToTable($trace, $frameIndex);
        foreach ($traceTable as &$traceItem) {
            $traceItem['source_position'] = $this->callSourceMapHandler($traceItem['source_position']);
        }
        unset($traceItem);
        $this->table($traceTable, $newLine);

        return $this;
    }

    protected function showCoroutine(Coroutine $coroutine, bool $newLine = true): static
    {
        $debugInfo = static::getSimpleInfoOfCoroutine($coroutine, false);
        $trace = static::getTraceOfCoroutine($coroutine);
        $this->table([$debugInfo], !$trace);
        if ($trace) {
            $this->cr()->showTrace($trace, null, $newLine);
        }

        return $this;
    }

    /**
     * @param Coroutine[] $coroutines
     */
    public function showCoroutines(array $coroutines): static
    {
        $map = [];
        foreach ($coroutines as $coroutine) {
            if ($coroutine === Coroutine::getCurrent()) {
                continue;
            }
            $info = static::getSimpleInfoOfCoroutine($coroutine, true);
            $info['source_position'] = $this->callSourceMapHandler($info['source_position']);
            $map[] = $info;
        }

        return $this->table($map);
    }

    /**
     * @param array<array{
     *     'file': string|null,
     *     'line': int|null,
     * }> $trace
     */
    public function showSourceFileContentByTrace(array $trace, int $frameIndex, bool $following = false): static
    {
        $file = null;
        $line = 0;
        try {
            $contentTable = $this::getSourceFileContentByTrace($trace, $frameIndex, $file, $line);
        } catch (DebuggerException $exception) {
            $this->lf();
            throw $exception;
        }
        $this
            ->setCurrentSourceFile($file)
            ->setCurrentSourceFileLine($line);
        if (!$contentTable) {
            return $this->lf();
        }
        if ($following) {
            $this->cr();
        }

        return $this->table($contentTable);
    }

    protected function showFollowingSourceFileContent(int $lineCount = DebuggerHelper::SOURCE_FILE_DEFAULT_LINE_COUNT): static
    {
        $sourceFile = $this->getCurrentSourceFile();
        if (!$sourceFile) {
            throw new DebuggerException('No source file was selected');
        }
        $line = $this->getCurrentSourceFileLine();

        return $this
            ->table(DebuggerHelper::getFollowingSourceFileContent($sourceFile, $line, $lineCount))
            ->setCurrentSourceFileLine($line + $lineCount - 1);
    }

    protected static function isNoOtherCoroutinesRunning(): bool
    {
        foreach (Coroutine::getAll() as $coroutine) {
            if ($coroutine === Coroutine::getCurrent()) {
                continue;
            }

            return false;
        }

        return true;
    }

    public function logo(): static
    {
        return $this->clear()->out($this::SDB)->lf();
    }

    public function run(string $keyword = ''): static
    {
        if ($this->reloading) {
            $this->reloading = false;
            goto _recvLoop;
        }
        $this->setCursorVisibility(true);
        if (static::isNoOtherCoroutinesRunning()) {
            $this->daemon = false;
            $this->logo()->out('Enter \'r\' to run your program');
            goto _recvLoop;
        }
        if ($keyword !== '') {
            $this->lf()->out("You can input '{$keyword}' to to call out the debug interface...");
        }
        _restart:
        if ($keyword !== '') {
            while ($in = $this->in(false)) {
                if (trim($in) === $keyword) {
                    break;
                }
            }
        }
        $this->logo();
        _recvLoop:
        while ($in = $this->in()) {
            if ($in === "\n") {
                $in = $this->getLastCommand();
            }
            $this->setLastCommand($in);
            _next:
            try {
                $lines = array_filter(explode("\n", $in));
                foreach ($lines as $line) {
                    $arguments = explode(' ', $line);
                    foreach ($arguments as &$argument) {
                        $argument = trim($argument);
                    }
                    unset($argument);
                    $arguments = array_filter($arguments, static fn(string $value) => $value !== '');
                    $command = array_shift($arguments);
                    $command = strtolower($command);
                    $command = $this::convertCommandShortNameToFullName($command);
                    switch ($command) {
                        case 'quit':
                        case 'exit':
                            $this->clear();
                            if ($keyword !== '' && !static::isNoOtherCoroutinesRunning()) {
                                /* we can input keyword to call out the debugger later */
                                goto _restart;
                            }
                            goto _quit;
                        case 'run':
                            if ($this->daemon) {
                                throw new DebuggerException('Debugger is already running');
                            }
                            $args = func_get_args();
                            Coroutine::run(function () use ($args): void {
                                $this->reloading = true;
                                $this->daemon = true;
                                $this
                                    ->out('Program is running...')
                                    ->run(...$args);
                            });
                            goto _quit;
                        case null:
                            break;
                        default:
                            $this->executeCommand($command, $arguments);
                    }
                }
            } catch (DebuggerException $exception) {
                $this->exception($exception->getMessage());
            } catch (Throwable $throwable) {
                $this->error((string) $throwable);
            }
        }

        _quit:
        return $this;
    }

    public static function runOnTTY(string $keyword = 'sdb'): static
    {
        return static::getInstance()->run($keyword);
    }
}
