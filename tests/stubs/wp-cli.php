<?php

/**
 * A stand-in for the parts of WP-CLI the command uses. error() throws
 * CliExit with code 1 instead of exiting, so tests can assert on it.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects,PSR1.Classes.ClassDeclaration,Squiz.Functions.GlobalFunction

namespace {
    final class CliExit extends RuntimeException
    {
    }

    final class WP_CLI
    {
        /** @var list<string> */
        public static array $out = [];
        /** @var list<string> */
        public static array $commands = [];

        public static function reset(): void
        {
            self::$out = [];
        }

        public static function add_command(string $name, string $class): void
        {
            self::$commands[] = $name;
        }

        public static function line(string $message = ''): void
        {
            self::$out[] = $message;
        }

        public static function log(string $message): void
        {
            self::$out[] = $message;
        }

        public static function success(string $message): void
        {
            self::$out[] = 'Success: ' . $message;
        }

        public static function warning(string $message): void
        {
            self::$out[] = 'Warning: ' . $message;
        }

        public static function error(string $message): never
        {
            self::$out[] = 'Error: ' . $message;
            throw new CliExit($message, 1);
        }

        public static function text(): string
        {
            return implode("\n", self::$out);
        }
    }
}

namespace WP_CLI\Utils {
    /**
     * @param list<array<string, mixed>> $items
     * @param list<string>|string $fields
     */
    function format_items(string $format, array $items, array|string $fields): void
    {
        $fields = is_string($fields) ? explode(',', $fields) : $fields;
        $rows = [];
        foreach ($items as $item) {
            $row = [];
            foreach ($fields as $field) {
                $row[$field] = $item[$field] ?? null;
            }
            $rows[] = $row;
        }
        if ('json' === $format) {
            \WP_CLI::line((string) json_encode($rows));
            return;
        }
        if ('csv' === $format) {
            \WP_CLI::line(implode(',', $fields));
            foreach ($rows as $row) {
                \WP_CLI::line(implode(',', array_map('strval', $row)));
            }
            return;
        }
        \WP_CLI::line(implode("\t", $fields));
        foreach ($rows as $row) {
            \WP_CLI::line(implode("\t", array_map('strval', $row)));
        }
    }
}
