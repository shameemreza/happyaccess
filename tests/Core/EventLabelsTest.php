<?php
/**
 * EventLabels tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\EventLabels;
use HappyAccess\Core\Privacy;

class EventLabelsTest extends WP_UnitTestCase {

	public function test_every_classified_event_has_a_label() {
		$events  = array_merge( Privacy::ADMIN_EVENTS, Privacy::AGENT_EVENTS, Privacy::CORE_EVENTS );
		$labels  = EventLabels::all();
		$missing = array_values( array_diff( $events, array_keys( $labels ) ) );
		$this->assertSame( array(), $missing, "Add a label in EventLabels::all() for:\n" . implode( "\n", $missing ) );
	}

	public function test_every_user_event_has_a_label() {
		$labels  = EventLabels::all();
		$missing = array_values( array_diff( Privacy::USER_EVENTS, array_keys( $labels ) ) );
		$this->assertSame( array(), $missing, "Add a label in EventLabels::all() for:\n" . implode( "\n", $missing ) );
	}

	public function test_the_two_step_events_are_user_events() {
		$expected = array( 'twostep_enabled', 'twostep_disabled', 'twostep_passed', 'twostep_failed', 'twostep_locked', 'twostep_reset', 'twostep_backup_used', 'twostep_backup_regenerated' );
		$this->assertSame( array(), array_values( array_diff( $expected, Privacy::USER_EVENTS ) ) );
	}

	public function test_labels_are_plain_sentence_case_text() {
		foreach ( EventLabels::all() as $key => $label ) {
			$this->assertNotSame( '', $label, $key );
			$this->assertSame( ucfirst( $label ), $label, $key );
			$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}_]/u', $label, $key );
		}
	}

	public function test_known_keys() {
		$this->assertSame( 'Support pass created', EventLabels::label( 'grant_created' ) );
		$this->assertSame( 'Administrator account made', EventLabels::label( 'admin_account_created' ) );
		$this->assertSame( 'Administrator login details changed', EventLabels::label( 'admin_account_changed' ) );
	}

	public function test_unknown_key_is_made_readable() {
		$this->assertSame( 'Some event', EventLabels::label( 'some_event' ) );
		$this->assertSame( '', EventLabels::label( '' ) );
	}
}
