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
/**
 * Author: Twosee <twose@qq.com>
 * Date: 2024/3/24 02:05
 */

namespace Swow\Debug\Debugger;

use Swow\Socket;

trait DebuggerIoTrait
{
    protected Socket $input;

    protected Socket $output;

    protected Socket $error;

    public function __constructDebuggerIo(): void
    {
        $this->input = (new Socket(Socket::TYPE_STDIN))->setReadTimeout(-1);
        $this->output = new Socket(Socket::TYPE_STDOUT);
        $this->error = new Socket(Socket::TYPE_STDERR);
    }

    public function in(bool $prefix = true): string
    {
        if ($prefix) {
            $this->out("\r> ", false);
        }

        return $this->input->recvString();
    }

    public function out(string $string = '', bool $newline = true): static
    {
        $this->output->write([$string, $newline ? "\n" : null]);

        return $this;
    }

    public function exception(string $string = '', bool $newline = true): static
    {
        $this->output->write([$string, $newline ? "\n" : null]);

        return $this;
    }

    public function error(string $string = '', bool $newline = true): static
    {
        $this->error->write([$string, $newline ? "\n" : null]);

        return $this;
    }

    public function cr(): static
    {
        return $this->out("\r", false);
    }

    public function lf(): static
    {
        return $this->out("\n", false);
    }

    public function clear(): static
    {
        $this->output->send("\033c");

        return $this;
    }

    /**
     * @param array<mixed> $table
     */
    public function table(array $table, bool $newline = true): static
    {
        return $this->out(DebuggerHelper::tableFormat($table), $newline);
    }

    protected function setCursorVisibility(bool $bool): static
    {
        // TODO tty check support
        /* @phpstan-ignore-next-line */
        if (1 /* is tty */) {
            $this->out("\033[?25" . ($bool ? 'h' : 'l'));
        }

        return $this;
    }
}
