from pathlib import Path

path = Path('src/Compile/Factory/FactoryCodeGenerator.php')
text = path.read_text()
old = '''            $parts[] = sprintf(
                <<<'PHP'
if ($entry !== null) {
    $entry->__construct(%s);

    return $entry;
}

return new \\%s(%s);
PHP,
                implode(', ', $arguments),
                $this->className,
                implode(', ', $arguments),
            );
'''
new = '''            $initializeExisting = $constructor->isPublic()
                ? sprintf(
                    <<<'PHP'
if ($entry !== null) {
    $entry->__construct(%s);

    return $entry;
}
PHP,
                    implode(', ', $arguments),
                )
                : sprintf(
                    <<<'PHP'
if ($entry !== null) {
    $constructor = self::%s()->getConstructor()
        ?? throw new \\LogicException('Compiled lazy initializer lost its constructor.');

    $constructor->invokeArgs($entry, [%s]);

    return $entry;
}
PHP,
                    $this->classHelper(),
                    implode(', ', $arguments),
                );

            $parts[] = sprintf(
                <<<'PHP'
%s

return new \\%s(%s);
PHP,
                $initializeExisting,
                $this->className,
                implode(', ', $arguments),
            );
'''
if text.count(old) != 1:
    raise SystemExit(f'expected one FactoryCodeGenerator target, found {text.count(old)}')
path.write_text(text.replace(old, new))
