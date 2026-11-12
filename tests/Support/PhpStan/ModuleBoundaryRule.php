<?php

declare(strict_types=1);

namespace Tests\Support\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * PHPStan rule enforcing {@see ModuleBoundary} on every `use` statement of a namespace.
 *
 * @implements Rule<Namespace_>
 */
final class ModuleBoundaryRule implements Rule
{
    public function getNodeType(): string
    {
        return Namespace_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $node->name?->toString() ?? '';
        $errors = [];

        foreach ($node->stmts as $statement) {
            $imports = [];
            if ($statement instanceof Use_) {
                foreach ($statement->uses as $use) {
                    $imports[] = [$use->name->toString(), $use->getStartLine()];
                }
            } elseif ($statement instanceof GroupUse) {
                foreach ($statement->uses as $use) {
                    $imports[] = [$statement->prefix->toString() . '\\' . $use->name->toString(), $use->getStartLine()];
                }
            }

            foreach ($imports as [$imported, $line]) {
                $message = ModuleBoundary::violation($namespace, $imported);
                if ($message !== null) {
                    $errors[] = RuleErrorBuilder::message($message)->identifier('module.boundary')->line($line)->build();
                }
            }
        }

        return $errors;
    }
}
