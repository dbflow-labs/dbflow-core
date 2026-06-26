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

namespace DbflowLabs\Core\Actions;

use DbflowLabs\Core\Contracts\ActionHandler;
use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Models\WorkflowInstance;

final class LocalStatusUpdateHandler implements ActionHandler
{
    public function execute(ActionNode $node, WorkflowInstance $instance): void
    {
        $payload = $node->payload() ?? [];
        $metadata = is_array($instance->metadata) ? $instance->metadata : [];

        if (isset($payload['metadata']) && is_array($payload['metadata'])) {
            $metadata = array_merge($metadata, $payload['metadata']);
        }

        if (isset($payload['metadata_key']) && array_key_exists('metadata_value', $payload)) {
            $metadata[(string) $payload['metadata_key']] = $payload['metadata_value'];
        }

        if ($metadata === (is_array($instance->metadata) ? $instance->metadata : [])) {
            return;
        }

        $instance->forceFill(['metadata' => $metadata])->save();
    }
}
