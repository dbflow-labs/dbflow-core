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

namespace DbflowLabs\Core\Capabilities;

use DbflowLabs\Core\Enums\RuntimeCapability;

/**
 * Core-owned runtime capability registry.
 * Filament/Pro may query this later; they must not become the source of truth.
 */
final class RuntimeCapabilityRegistry
{
    /**
     * @var array<string, true>
     */
    private array $enabled = [];

    public function enable(RuntimeCapability|string $capability): void
    {
        $this->enabled[$this->normalize($capability)] = true;
    }

    public function disable(RuntimeCapability|string $capability): void
    {
        unset($this->enabled[$this->normalize($capability)]);
    }

    public function has(RuntimeCapability|string $capability): bool
    {
        return isset($this->enabled[$this->normalize($capability)]);
    }

    /**
     * @return list<string>
     */
    public function enabledCapabilities(): array
    {
        $keys = array_keys($this->enabled);
        sort($keys);

        return $keys;
    }

    /**
     * Stage 1.1-A defaults: only context schema contract support is enabled.
     */
    public function registerStage11ADefaults(): void
    {
        $this->enable(RuntimeCapability::ContextSchemaV11);
    }

    /**
     * Stage 1.1-B defaults: context schema plus delegation runtime.
     */
    public function registerStage11BDefaults(): void
    {
        $this->registerStage11ADefaults();
        $this->enable(RuntimeCapability::Delegation);
    }

    /**
     * Stage 1.1-C defaults: context schema, delegation, and SLA runtime.
     */
    public function registerStage11CDefaults(): void
    {
        $this->registerStage11BDefaults();
        $this->enable(RuntimeCapability::Sla);
    }

    /**
     * Stage 1.1-D defaults: reliable action runtime.
     * Outbound webhook is enabled separately after security closure.
     */
    public function registerStage11DDefaults(): void
    {
        $this->registerStage11CDefaults();
        $this->enable(RuntimeCapability::ReliableAction);
    }

    /**
     * Stage 1.1-D webhook closure: enable outbound_webhook after security hardening.
     */
    public function registerStage11DWebhookDefaults(): void
    {
        $this->registerStage11DDefaults();
        $this->enable(RuntimeCapability::OutboundWebhook);
    }

    private function normalize(RuntimeCapability|string $capability): string
    {
        return $capability instanceof RuntimeCapability ? $capability->value : $capability;
    }
}
