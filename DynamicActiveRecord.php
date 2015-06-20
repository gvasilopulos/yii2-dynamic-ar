<?php
/**
 * @link https://github.com/tom--/dynamic-ar
 * @copyright Copyright (c) 2015 Spinitron LLC
 * @license http://opensource.org/licenses/ISC
 */

namespace spinitron\dynamicAr;

use Yii;
use yii\base\Exception;
use yii\base\UnknownPropertyException;
use yii\db\ActiveRecord;

/**
 * DynamicActiveRecord represents relational data with structured dynamic attributes.
 *
 * DynamicActiveRecord adds NoSQL-like structured dynamic attributes to Yii 2.0 ActiveRecord.
 * Dynamic attributes are stored in Maria 10.0+ **Dynamic Columns**, PostgreSQL 9.4+
 * **jsonb** columns, or otherwise in plain JSON, providing something like a NoSQL
 * document store within SQL relational DB tables.
 *
 * > NOTE: In this version only Maria 10.0+ is supported.
 *
 * If the DBMS supports using the dynamic attributes in queries (Maria, PostgreSQL) then
 * DynamicActiveRecord combines with DynamicActiveQuery to provide an abstract
 * interface for querying dynamic attributes.
 *
 * See the README of yii2-dynamic-ar extension for full description.
 *
 * @author Tom Worster <fsb@thefsb.org>
 */
class DynamicActiveRecord extends ActiveRecord
{
    const PARAM_PREFIX = ':dqp';

    private $dynamicAttributes = [];
    const DATA_URI_PREFIX = 'data:application/octet-stream;base64,';
    /**
     * @var int
     */
    protected static $placeholderCounter;

    public function __get($name)
    {
        return $this->getAttribute($name);
    }

    public function __set($name, $value)
    {
        $this->setAttribute($name, $value);
    }

    public function __isset($name)
    {
        return $this->issetAttribute($name);
    }

    public function __unset($name)
    {
        $this->unsetAttribute($name);
    }

    /**
     * Returns a model attribute value.
     *
     * @param string $name attribute name. Dot notation for structured dynamic attributes allowed.
     *
     * @return mixed|null
     */
    public function getAttribute($name)
    {
        try {
            return parent::__get($name);
        } catch (UnknownPropertyException $ignore) {
        }

        $path = explode('.', $name);
        $ref = &$this->dynamicAttributes;

        foreach ($path as $key) {
            if (!isset($ref[$key])) {
                return null;
            }
            $ref = &$ref[$key];
        }

        return $ref;
    }

    /**
     * Sets a model attribute.
     *
     * @param string $name attribute name. Dot notation for structured dynamic attributes allowed.
     * @param mixed $value the attribute value
     */
    public function setAttribute($name, $value)
    {
        try {
            parent::__set($name, $value);

            return;
        } catch (UnknownPropertyException $ignore) {
        }

        $path = explode('.', $name);
        $ref = &$this->dynamicAttributes;

        // Walk forwards through $path to find the deepest key already set.
        do {
            $key = $path[0];
            if (isset($ref[$key])) {
                $ref = &$ref[$key];
                array_shift($path);
            } else {
                break;
            }
        } while ($path);

        // If the whole path already existed then we can just set it.
        if (!$path) {
            $ref = $value;

            return;
        }

        // There is remaining path so we have to set a new leaf with the first
        // part of the remaining path as key. But first, if there is any path
        // beyond that then we need build an array to set as the new leaf value.
        while (count($path) > 1) {
            $key = array_pop($path);
            $value = [$key => $value];
        }
        $ref[$path[0]] = $value;
    }

    /**
     * Returns if a model attribute is set.
     *
     * @param string $name attribute name. Dot notation for structured dynamic attributes allowed.
     *
     * @return bool true if the attribute is set
     */
    public function issetAttribute($name)
    {
        try {
            if (parent::__get($name) !== null) {
                return true;
            }
        } catch (Exception $ignore) {
        }

        $path = explode('.', $name);
        $ref = &$this->dynamicAttributes;

        foreach ($path as $key) {
            if (!isset($ref[$key])) {
                return false;
            }
            $ref = &$ref[$key];
        }

        return true;
    }

    /**
     * Unset a model attribute.
     *
     * @param string $name attribute name. Dot notation for structured dynamic attributes allowed.
     */
    public function unsetAttribute($name)
    {
        try {
            parent::__unset($name);
        } catch (\Exception $ignore) {
        }

        if ($this->issetAttribute($name)) {
            $this->setAttribute($name, null);
        }
    }

    /**
     * @inheritdoc
     */
    public function fields()
    {
        $fields = array_keys((array) $this->dynamicAttributes);

        return array_merge(parent::fields(), $fields);
    }

    /**
     * Return a list of string array keys in dotted notation, recursing subarrays.
     *
     * @param string $prefix Prefix returned array keys with this string
     * @param array $array An array of attributeName => value pairs
     *
     * @return array The list of attribute names in dotted notation
     */
    protected static function dotAttributes($prefix, $array)
    {
        $fields = [];
        foreach ($array as $key => $value) {
            if (is_string($key)) {
                $newPos = $prefix . '.' . $key;
                $fields[] = $newPos;
                if (is_array($value)) {
                    $fields = array_merge($fields, static::dotAttributes($newPos, $value));
                }
            }
        }

        return $fields;
    }

    /**
     * Return a list of all model attribute names recursing structured dynamic attributes.
     *
     * @return string[] The list of all attribute names
     * @throws Exception
     */
    public function allAttributes()
    {
        return array_merge(
            array_values(parent::fields()),
            static::dotAttributes(static::dynamicColumn(), $this->dynamicAttributes)
        );
    }

    public static function placeholder()
    {
        if (static::$placeholderCounter === null) {
            static::$placeholderCounter = 1;
        } else {
            static::$placeholderCounter += 1;
        }

        return static::PARAM_PREFIX . static::$placeholderCounter;
    }

    /**
     * Encode as data URIs strings that JSON cannot express.
     *
     * @param $value
     *
     * @return string
     */
    public static function encodeForMaria($value)
    {
        return is_string($value)
        && (!mb_check_encoding($value, 'UTF-8') || strpos($value, self::DATA_URI_PREFIX) === 0)
            ? self::DATA_URI_PREFIX . base64_encode($value)
            : $value;
    }

    /**
     * Decode strings encoded as data URIs
     *
     * @param $value
     *
     * @return string
     */
    public static function decodeForMaria($value)
    {
        return is_string($value) && strpos($value, self::DATA_URI_PREFIX) === 0
            ? file_get_contents($value)
            : $value;
    }

    /**
     * Replacement for PHP's array walk and map builtins.
     *
     * @param $array
     * @param $method
     */
    protected static function walk(& $array, $method)
    {
        if (is_scalar($array)) {
            $array = static::$method($array);

            return;
        }

        $replacements = [];
        foreach ($array as $key => & $value) {
            if (is_scalar($value) || $value === null) {
                $value = static::$method($value);
            } else {
                static::walk($value, $method);
            }
            $newKey = static::$method($key);
            if ($newKey !== $key) {
                $replacements[$newKey] = $value;
                unset($array[$key]);
            }
        }
        foreach ($replacements as $key => $value2) {
            $array[$key] = $value2;
        }
    }

    /**
     * Encodes as data URIs any "binary' strings in an array.
     *
     * @param $array
     */
    public static function encodeArrayForMaria(& $array)
    {
        self::walk($array, 'encodeForMaria');
    }

    /**
     * Encodes any data URI strings in an array.
     *
     * @param $array
     */
    public static function decodeArrayForMaria(& $array)
    {
        self::walk($array, 'decodeForMaria');
    }

    /**
     * Create the SQL and parameter bindings for setting attributes as dynamic fields in a DB record.
     *
     * @param array $attrs Name and value pairs of dynamic fields to be saved in DB
     * @param array $params Expression parameters for binding, passed by reference
     *
     * @return string SQL for a DB Expression
     * @throws \yii\base\Exception
     */
    private static function dynColSqlMaria(array $attrs, & $params)
    {
        $sql = [];
        foreach ($attrs as $key => $value) {
            if (is_object($value)) {
                $value = (array) $value;
            }
            if ($value === [] || $value === null) {
                continue;
            }

            $phKey = static::placeholder();
            $phValue = static::placeholder();
            $sql[] = $phKey;
            $params[$phKey] = $key;

            if (is_scalar($value)) {
                $sql[] = $phValue;
                $params[$phValue] = $value;
            } elseif (is_array($value)) {
                $sql[] = static::dynColSqlMaria($value, $params);
            }
        }

        return $sql === [] ? 'null' : 'COLUMN_CREATE(' . implode(',', $sql) . ')';
    }

    /**
     * @param $attrs
     *
     * @return null|\yii\db\Expression
     */
    public static function dynColExpression($attrs)
    {
        if (!$attrs) {
            return null;
        }

        $params = [];

        // todo For now we only have Maria. Add PgSQL and generic JSON.
        static::encodeArrayForMaria($attrs);
        $sql = static::dynColSqlMaria($attrs, $params);

        return new \yii\db\Expression($sql, $params);
    }

    /**
     * Decode a serialized blob of dynamic attributes.
     *
     * For now the format is JSON for Maria, PgSQL and unaware DBs.
     *
     * @param string $encoded Serialized array of attributes in DB-specific form
     *
     * @return array Dynamic attributes in name => value pairs (possibly nested)
     */
    public static function dynColDecode($encoded)
    {
        // Maria has a bug in its COLUMN_JSON funcion in which it fails to escape the
        // control characters U+0000 through U+001F. This causes JSON decoders to fail.
        // This workaround escapes those characters.
        $encoded = preg_replace_callback(
            '/[\x00-\x1f]/',
            function ($matches) {
                return sprintf('\u00%02x', ord($matches[0]));
            },
            $encoded
        );

        $decoded = json_decode($encoded, true);
        if ($decoded) {
            static::decodeArrayForMaria($decoded);
        }

        return $decoded;
    }

    /**
     * Specifies the name of the table column containing dynamic attributes.
     *
     * @return string Name of the table column containing dynamic column data
     * @throws \yii\base\Exception if not overriden by descendent class.
     */
    public static function dynamicColumn()
    {
        throw new \yii\base\Exception('A DynamicActiveRecord class must override "dynamicColumn()"');
    }

    /**
     * @inheritdoc
     */
    public static function find()
    {
        return Yii::createObject(DynamicActiveQuery::className(), [get_called_class()]);
    }

    /**
     * @inheritdoc
     */
    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        $this->setAttribute(static::dynamicColumn(), static::dynColExpression($this->dynamicAttributes));

        return true;
    }

    /**
     * @inheritdoc
     */
    public static function populateRecord($record, $row)
    {
        $dynCol = static::dynamicColumn();
        if (isset($row[$dynCol])) {
            $record->dynamicAttributes = static::dynColDecode($row[$dynCol]);
        }
        parent::populateRecord($record, $row);
    }

    /**
     * @inheritdoc
     */
    public function refresh()
    {
        if (!parent::refresh()) {
            return false;
        }

        $dynCol = static::dynamicColumn();
        if (isset($this->attributes[$dynCol])) {
            $this->dynamicAttributes = static::dynColDecode($this->attributes[$dynCol]);
        }

        return true;
    }
}
