<?php

namespace Marclie\Booking\Tests\Unit\Bricks;

use Marclie\Booking\Bricks\Loader;
use PHPUnit\Framework\TestCase;

final class LoaderTest extends TestCase {

	public function testClassIdsByCategoryPrefix(): void {
		$classes    = array(
			array( 'id' => 'k1', 'name' => 'w-button-primary', 'category' => 'cat-components' ),
			array( 'id' => 'k2', 'name' => 'w-datepicker__day', 'category' => 'cat-components' ),
			array( 'id' => 'k3', 'name' => 'w-stay-catalogue__grid', 'category' => 'cat-sections' ),
			array( 'id' => 'k4', 'name' => 'w-header', 'category' => 'cat-chrome' ),
			array( 'id' => 'k5', 'name' => 'loose' ),
		);
		$categories = array(
			array( 'id' => 'cat-components', 'name' => 'Components' ),
			array( 'id' => 'cat-sections', 'name' => 'Page sections' ),
			array( 'id' => 'cat-chrome', 'name' => 'Header & footer' ),
		);

		$this->assertSame( array( 'k1', 'k2' ), Loader::classIds( $classes, $categories, Loader::CLASS_CATEGORIES ) );
		$this->assertSame( array(), Loader::classIds( $classes, false, Loader::CLASS_CATEGORIES ), 'no categories option' );
		$this->assertSame( array(), Loader::classIds( $classes, $categories, array( '' ) ), 'an empty prefix matches nothing' );
	}

	public function testClassIdsByBlock(): void {
		$classes    = array(
			array( 'id' => 's1', 'name' => 'w-stay-catalogue', 'category' => 'cat-sections' ),
			array( 'id' => 's2', 'name' => 'w-stay-catalogue__grid', 'category' => 'cat-sections' ),
			array( 'id' => 's3', 'name' => 'w-stay-catalogue--wide', 'category' => 'cat-sections' ),
			array( 'id' => 's4', 'name' => 'w-stay-catalogue-extra', 'category' => 'cat-sections' ),
			array( 'id' => 's5', 'name' => 'w-booking-checkout__summary', 'category' => 'cat-sections' ),
			array( 'id' => 's6', 'name' => 'w-stay-catalogue__grid', 'category' => 'cat-components' ),
		);
		$categories = array(
			array( 'id' => 'cat-components', 'name' => 'Components' ),
			array( 'id' => 'cat-sections', 'name' => 'Page sections' ),
		);

		$this->assertSame( array( 's1', 's2', 's3' ), Loader::classIds( $classes, $categories, array( Loader::SECTION_CATEGORY ), array( 'w-stay-catalogue' ) ) );
		$this->assertTrue( Loader::inBlocks( 'w-booking-checkout__summary', array( 'w-stay-catalogue', 'w-booking-checkout' ) ) );
		$this->assertFalse( Loader::inBlocks( 'w-booking-checkouts', array( 'w-booking-checkout' ) ) );
	}

	public function testElementBlocksCoverEveryElement(): void {
		foreach ( array_keys( Loader::ELEMENTS ) as $name ) {
			$this->assertArrayHasKey( $name, Loader::ELEMENT_BLOCKS );
		}
	}

	public function testElementNamesMatchClassFiles(): void {
		foreach ( Loader::ELEMENTS as $name => $class ) {
			$this->assertStringStartsWith( 'mbk-', $name );
			$short = substr( $class, strrpos( $class, '\\' ) + 1 );
			$this->assertFileExists( dirname( __DIR__, 4 ) . '/src/Bricks/Elements/' . $short . '.php' );
		}
	}
}
