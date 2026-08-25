<?php

declare(strict_types=1);

namespace Componenta\DI\Cache\Internal;

/** @internal */
final class GeneratedExpressionFormatter
{
    private function __construct() {}

    public static function indent(string $code, string $baseIndent): string
    {
        if ($baseIndent === '' || !str_contains($code, "\n")) {
            return $code;
        }

        $tokens = \PhpToken::tokenize('<?php ' . $code);
        $result = '';

        foreach ($tokens as $index => $token) {
            if ($index === 0 && $token->is(T_OPEN_TAG)) {
                continue;
            }

            if (self::isStringLiteralToken($token)) {
                $result .= $token->text;
                continue;
            }

            $result .= str_replace("\n", "\n" . $baseIndent, $token->text);
        }

        return $result;
    }

    private static function isStringLiteralToken(\PhpToken $token): bool
    {
        return $token->is([
            T_CONSTANT_ENCAPSED_STRING,
            T_ENCAPSED_AND_WHITESPACE,
            T_START_HEREDOC,
            T_END_HEREDOC,
            T_INLINE_HTML,
        ]);
    }
}
