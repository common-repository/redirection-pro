<?php

namespace RedirectionPro;

/**
 * The primary reason for this class is to provide a common structure and pattern for creating 
 * singleton classes within the RedirectionPro namespace. The singleton pattern ensures that 
 * there is only one instance of a class, which can be accessed globally. 
 * By defining the create method in the abstract class, it enforces a consistent way to create 
 * singleton instances across different classes in the namespace.
 * 
 * @package RedirectionPro
 * @since 1.0.0
 * @abstract
 */
abstract class SingletonClass
{
    abstract public static function create(...$args);
}