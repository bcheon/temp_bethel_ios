<?php
/**
 * BKC_Groups — group whitelist and topic mapping.
 *
 * @package bkc-push
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static helper for FCM topic group management.
 */
class BKC_Groups {

	/**
	 * Allowed group identifiers.
	 */
	const WHITELIST = [ 'all', 'youth', 'newfamily' ];

	/**
	 * Maps group identifier to FCM topic name.
	 */
	const TOPIC_MAP = [
		'all'       => 'bkc_all',
		'youth'     => 'bkc_youth',
		'newfamily' => 'bkc_newfam',
	];

	/**
	 * Check if a single group identifier is valid.
	 *
	 * @param string $group Group identifier.
	 * @return bool
	 */
	public static function is_valid( string $group ): bool {
		return in_array( $group, self::WHITELIST, true );
	}

	/**
	 * Return the FCM topic name for a group.
	 *
	 * @param string $group Group identifier.
	 * @return string
	 * @throws \InvalidArgumentException If group is unknown.
	 */
	public static function to_topic( string $group ): string {
		if ( ! isset( self::TOPIC_MAP[ $group ] ) ) {
			throw new \InvalidArgumentException( "Unknown group: {$group}" );
		}
		return self::TOPIC_MAP[ $group ];
	}

	/**
	 * Validate that every element in an array is a known group.
	 *
	 * @param array $groups Array of group identifiers.
	 * @return bool True when all groups are valid and array is non-empty.
	 */
	public static function validate_array( array $groups ): bool {
		if ( empty( $groups ) ) {
			return false;
		}
		foreach ( $groups as $group ) {
			if ( ! self::is_valid( $group ) ) {
				return false;
			}
		}
		return true;
	}
}
