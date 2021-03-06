<?php
# Generated by the protocol buffer compiler.  DO NOT EDIT!
# source: core/Tron.proto

namespace Protocol\BlockInventory;

use UnexpectedValueException;

/**
 * Protobuf type <code>protocol.BlockInventory.Type</code>
 */
class Type
{
    /**
     * Generated from protobuf enum <code>SYNC = 0;</code>
     */
    const SYNC = 0;
    /**
     * Generated from protobuf enum <code>ADVTISE = 1;</code>
     */
    const ADVTISE = 1;
    /**
     * Generated from protobuf enum <code>FETCH = 2;</code>
     */
    const FETCH = 2;

    private static $valueToName = [
        self::SYNC => 'SYNC',
        self::ADVTISE => 'ADVTISE',
        self::FETCH => 'FETCH',
    ];

    public static function name($value)
    {
        if (!isset(self::$valueToName[$value])) {
            throw new UnexpectedValueException(sprintf(
                    'Enum %s has no name defined for value %s', __CLASS__, $value));
        }
        return self::$valueToName[$value];
    }


    public static function value($name)
    {
        $const = __CLASS__ . '::' . strtoupper($name);
        if (!defined($const)) {
            throw new UnexpectedValueException(sprintf(
                    'Enum %s has no value defined for name %s', __CLASS__, $name));
        }
        return constant($const);
    }
}

// Adding a class alias for backwards compatibility with the previous class name.
class_alias(Type::class, \Protocol\BlockInventory_Type::class);

