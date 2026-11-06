<?php
/**
 * The Cloudflare rules: the skip rule only covers the exact payment webhooks, the cache
 * rule every route of the plugin, and applying them never touches other rules.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Unit;

use BookingManager\Cloudflare\Rules;
use PHPUnit\Framework\TestCase;

final class CloudflareRulesTest extends TestCase {

	public function test_the_skip_rule_names_each_webhook_exactly(): void {
		$expr = Rules::webhook_expression(
			array(
				'https://hotel.example/wp-json/booking-manager/v1/webhooks/stripe/3',
				'https://hotel.example/index.php?rest_route=/booking-manager/v1/webhooks/nexi/4',
			)
		);
		$this->assertStringStartsWith( '(http.request.method eq "POST" and starts_with(http.request.headers["content-type"][0], "application/json")', $expr );
		$this->assertStringContainsString( '(http.request.uri.path eq "/wp-json/booking-manager/v1/webhooks/stripe/3" and http.request.uri.query eq "")', $expr );
		$this->assertStringContainsString( '(http.request.uri.path eq "/index.php" and http.request.uri.query eq "rest_route=/booking-manager/v1/webhooks/nexi/4")', $expr );
		$this->assertStringNotContainsString( 'contains', $expr, 'a query that merely contains the route would open every other route' );
	}

	public function test_without_accounts_the_skip_rule_matches_nothing_real(): void {
		$expr = Rules::webhook_expression( array() );
		$this->assertStringContainsString( '/booking-manager-no-webhook', $expr );
		$this->assertStringNotContainsString( 'path eq "/")', $expr );
	}

	public function test_our_rules_are_updated_in_place_and_others_left_alone(): void {
		$desired  = Rules::cache_rule();
		$existing = array(
			array( 'id' => 'a', 'description' => 'Altro sito' ),
			array( 'id' => 'b' ) + $desired,
			array( 'id' => 'c' ) + $desired,
		);
		$ops = Rules::plan( $existing, $desired, 'last' );
		$ids = array_column( $ops, 'id' );
		$this->assertContains( 'c', $ids, 'our duplicate is removed' );
		$this->assertNotContains( 'a', $ids, 'never someone else\'s rule' );
		$this->assertStringStartsWith( Rules::PREFIX, Rules::waf_rule( array() )['description'] );
	}
}
