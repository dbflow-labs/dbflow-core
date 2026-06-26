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

namespace DbflowLabs\Core\Definitions;

final class Transition
{
    public function __construct(
        private readonly string $from,
        private readonly string $to,
        private readonly ?string $condition = null,
        private readonly ?int $priority = null,
        private readonly bool $isDefault = false,
        private readonly ?string $event = null,
    ) {}

    public function from(): string
    {
        return $this->from;
    }

    public function to(): string
    {
        return $this->to;
    }

    public function condition(): ?string
    {
        return $this->condition;
    }

    public function priority(): ?int
    {
        return $this->priority;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function event(): ?string
    {
        return $this->event;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $from = is_string($data[WorkflowDefinitionSchema::FIELD_FROM] ?? null)
            ? $data[WorkflowDefinitionSchema::FIELD_FROM]
            : '';
        $to = is_string($data[WorkflowDefinitionSchema::FIELD_TO] ?? null)
            ? $data[WorkflowDefinitionSchema::FIELD_TO]
            : '';

        $condition = $data[WorkflowDefinitionSchema::FIELD_CONDITION] ?? null;
        $priority = $data[WorkflowDefinitionSchema::FIELD_PRIORITY] ?? null;
        $event = $data['event'] ?? null;

        return new self(
            from: $from,
            to: $to,
            condition: is_string($condition) && $condition !== '' ? $condition : null,
            priority: is_numeric($priority) ? (int) $priority : null,
            isDefault: (bool) ($data[WorkflowDefinitionSchema::FIELD_IS_DEFAULT] ?? false),
            event: is_string($event) && $event !== '' ? $event : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            WorkflowDefinitionSchema::FIELD_FROM => $this->from,
            WorkflowDefinitionSchema::FIELD_TO => $this->to,
        ];

        if ($this->condition !== null) {
            $payload[WorkflowDefinitionSchema::FIELD_CONDITION] = $this->condition;
        }

        if ($this->priority !== null) {
            $payload[WorkflowDefinitionSchema::FIELD_PRIORITY] = $this->priority;
        }

        if ($this->isDefault) {
            $payload[WorkflowDefinitionSchema::FIELD_IS_DEFAULT] = true;
        }

        if ($this->event !== null) {
            $payload['event'] = $this->event;
        }

        return $payload;
    }
}
