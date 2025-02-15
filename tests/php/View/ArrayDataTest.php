<?php

namespace SilverStripe\View\Tests;

use SilverStripe\ORM\ArrayLib;
use SilverStripe\ORM\FieldType\DBVarchar;
use SilverStripe\Dev\Deprecation;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\View\ArrayData;
use stdClass;

class ArrayDataTest extends SapphireTest
{

    public function testViewabledataItemsInsideArraydataArePreserved()
    {
        /* ViewableData objects will be preserved, but other objects will be converted */
        $arrayData = new ArrayData(
            [
            "A" => new DBVarchar("A"),
            "B" => new stdClass(),
            ]
        );
        $this->assertEquals(DBVarchar::class, get_class($arrayData->A));
        $this->assertEquals(ArrayData::class, get_class($arrayData->B));
    }

    public function testWrappingANonEmptyObjectWorks()
    {
        $object = new ArrayDataTest\NonEmptyObject();
        $this->assertTrue(is_object($object));

        $arrayData = new ArrayData($object);

        $this->assertEquals("Apple", $arrayData->getField('a'));
        $this->assertEquals("Banana", $arrayData->getField('b'));
        $this->assertFalse($arrayData->hasField('c'));
    }

    public function testWrappingAnAssociativeArrayWorks()
    {
        $array = ["A" => "Alpha", "B" => "Beta"];
        $this->assertTrue(ArrayLib::is_associative($array));

        $arrayData = new ArrayData($array);

        $this->assertTrue($arrayData->hasField("A"));
        $this->assertEquals("Alpha", $arrayData->getField("A"));
        $this->assertEquals("Beta", $arrayData->getField("B"));
    }

    public function testRefusesToWrapAnIndexedArray()
    {
        $array = [0 => "One", 1 => "Two"];
        $this->assertFalse(ArrayLib::is_associative($array));
    }

    public function testSetField()
    {
        $arrayData = new ArrayData([]);

        $arrayData->setField('d', 'Delta');

        $this->assertTrue($arrayData->hasField('d'));
        $this->assertEquals('Delta', $arrayData->getField('d'));
    }

    public function testGetArray()
    {
        $originalDeprecation = Deprecation::dump_settings();
        Deprecation::notification_version('2.4');

        $array = [
            'Foo' => 'Foo',
            'Bar' => 'Bar',
            'Baz' => 'Baz'
        ];

        $arrayData = new ArrayData($array);

        $this->assertEquals($arrayData->toMap(), $array);

        Deprecation::restore_settings($originalDeprecation);
    }

    public function testArrayToObject()
    {
        $arr = ["test1" => "result1","test2"=>"result2"];
        $obj = ArrayData::array_to_object($arr);
        $objExpected = new stdClass();
        $objExpected->test1 = "result1";
        $objExpected->test2 = "result2";
        $this->assertEquals($obj, $objExpected, "Two objects match");
    }
}
