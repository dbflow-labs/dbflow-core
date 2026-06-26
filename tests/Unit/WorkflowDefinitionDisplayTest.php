<?php

/**
 * This file is part of the dbflow-labs/core package.
 *
 * Copyright (c) 2026 Baron Wang <hello@dbflow.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license MIT
 * @link    https://dbflow.dev
 * @see     https://github.com/dbflow-labs/dbflow-core
 */

declare(strict_types=1);

namespace DbflowLabs\Core\Tests\Unit;

use DbflowLabs\Core\Support\WorkflowDefinitionDisplay;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class WorkflowDefinitionDisplayTest extends TestCase
{
    #[Test]
    public function workflow_name_returns_em_dash_when_unresolvable(): void
    {
        $display = new WorkflowDefinitionDisplay;

        $this->assertSame("\u{2014}", $display->workflowName(null));
        $this->assertSame("\u{2014}", $display->workflowName(''));
    }

    #[Test]
    public function workflow_name_returns_fallback_when_translation_missing(): void
    {
        $display = new WorkflowDefinitionDisplay;

        $this->assertSame('Expense Approval', $display->workflowName('missing_key', 'Expense Approval'));
    }

    #[Test]
    public function node_label_returns_node_key_before_em_dash_fallback(): void
    {
        $display = new WorkflowDefinitionDisplay;

        $this->assertSame('manager_review', $display->nodeLabel(null, 'manager_review'));
    }

    #[Test]
    public function node_label_returns_em_dash_when_unresolvable(): void
    {
        $display = new WorkflowDefinitionDisplay;

        $this->assertSame("\u{2014}", $display->nodeLabel(null, null));
        $this->assertSame("\u{2014}", $display->nodeLabel(null, ''));
    }
}
