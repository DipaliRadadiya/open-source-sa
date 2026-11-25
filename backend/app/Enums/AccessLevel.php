<?php

namespace App\Enums;

/**
 * How much of one permission a role is granted.
 *
 * The `permission_role` pivot stores two booleans, but only three of their four combinations
 * are reachable: `PermissionResolver` sets `view` whenever `manage` is set. This enum gives the
 * role form one three-way choice instead of two checkboxes.
 */
enum AccessLevel: string
{
    case None = 'none';
    case View = 'view';
    case Manage = 'manage';

    /**
     * Collapse a stored pair back into the level it represents.
     *
     * `manage` is checked first on purpose: a legacy row can hold manage=true with view=false,
     * and it grants management, so it must not be shown as "no access".
     */
    public static function fromGrant(bool $view, bool $manage): self
    {
        return match (true) {
            $manage => self::Manage,
            $view => self::View,
            default => self::None,
        };
    }

    /**
     * The pair to store for this level, `manage` implying `view` as always.
     *
     * @return array{view: bool, manage: bool}
     */
    public function toGrant(): array
    {
        return [
            'view' => $this !== self::None,
            'manage' => $this === self::Manage,
        ];
    }

    public function title(): string
    {
        return __('access_level.'.$this->value.'.title');
    }

    public function description(): string
    {
        return __('access_level.'.$this->value.'.description');
    }

    /**
     * The three options a role form renders, weakest first, so the frontend hardcodes neither
     * labels nor order.
     *
     * @return array<int, array<string, string>>
     */
    public static function catalog(): array
    {
        return array_map(fn (self $level): array => [
            'key' => $level->value,
            'title' => $level->title(),
            'description' => $level->description(),
        ], self::cases());
    }
}
