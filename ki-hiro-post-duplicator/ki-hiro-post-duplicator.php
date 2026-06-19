<?php
/**
 * Plugin Name: Ki-Hi-Ro Post Duplicator
 * Description: 投稿・固定ページなどの記事一覧に「複製」を追加し、内容を下書きとしてコピーします。
 * Version: 1.0.0
 * Author: Ki-Hi-Ro
 * License: GPL-2.0-or-later
 * Text Domain: ki-hiro-post-duplicator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add the duplicate link to post list row actions.
 *
 * @param array   $actions Row actions.
 * @param WP_Post $post    Current post.
 * @return array
 */
function ki_hiro_post_duplicator_add_row_action( $actions, $post ) {
	$post_type_object = get_post_type_object( $post->post_type );

	if ( ! $post_type_object || ! current_user_can( 'edit_post', $post->ID ) ) {
		return $actions;
	}

	if ( ! $post_type_object->show_ui || 'attachment' === $post->post_type ) {
		return $actions;
	}

	$url = wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'ki_hiro_duplicate_post',
				'post'   => $post->ID,
			),
			admin_url( 'admin-post.php' )
		),
		'ki_hiro_duplicate_post_' . $post->ID
	);

	$actions['ki_hiro_duplicate'] = sprintf(
		'<a href="%1$s" aria-label="%2$s">%3$s</a>',
		esc_url( $url ),
		esc_attr( sprintf( '「%s」を複製', $post->post_title ) ),
		esc_html__( '複製', 'ki-hiro-post-duplicator' )
	);

	return $actions;
}
add_filter( 'post_row_actions', 'ki_hiro_post_duplicator_add_row_action', 10, 2 );
add_filter( 'page_row_actions', 'ki_hiro_post_duplicator_add_row_action', 10, 2 );

/**
 * Duplicate a post and redirect to the new draft's edit screen.
 */
function ki_hiro_post_duplicator_duplicate_post() {
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

	if ( ! $post_id ) {
		wp_die( esc_html__( '複製する記事が指定されていません。', 'ki-hiro-post-duplicator' ) );
	}

	check_admin_referer( 'ki_hiro_duplicate_post_' . $post_id );

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( esc_html__( 'この記事を複製する権限がありません。', 'ki-hiro-post-duplicator' ) );
	}

	$original = get_post( $post_id );

	if ( ! $original || 'attachment' === $original->post_type ) {
		wp_die( esc_html__( '複製元の記事が見つかりません。', 'ki-hiro-post-duplicator' ) );
	}

	$new_post_id = wp_insert_post(
		wp_slash(
			array(
				'post_author'           => get_current_user_id(),
				'post_content'          => $original->post_content,
				'post_title'            => $original->post_title,
				'post_excerpt'          => $original->post_excerpt,
				'post_status'           => 'draft',
				'post_type'             => $original->post_type,
				'comment_status'        => $original->comment_status,
				'ping_status'           => $original->ping_status,
				'post_password'         => $original->post_password,
				'post_parent'           => $original->post_parent,
				'menu_order'            => $original->menu_order,
				'to_ping'               => $original->to_ping,
				'pinged'                => $original->pinged,
				'post_content_filtered' => $original->post_content_filtered,
			)
		),
		true
	);

	if ( is_wp_error( $new_post_id ) ) {
		wp_die( esc_html( $new_post_id->get_error_message() ) );
	}

	ki_hiro_post_duplicator_copy_taxonomies( $post_id, $new_post_id, $original->post_type );
	ki_hiro_post_duplicator_copy_meta( $post_id, $new_post_id );

	wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $new_post_id ) );
	exit;
}
add_action( 'admin_post_ki_hiro_duplicate_post', 'ki_hiro_post_duplicator_duplicate_post' );

/**
 * Copy all taxonomy terms assigned to a post.
 *
 * @param int    $source_post_id Source post ID.
 * @param int    $new_post_id    New post ID.
 * @param string $post_type      Post type.
 */
function ki_hiro_post_duplicator_copy_taxonomies( $source_post_id, $new_post_id, $post_type ) {
	$taxonomies = get_object_taxonomies( $post_type );

	foreach ( $taxonomies as $taxonomy ) {
		$term_ids = wp_get_object_terms(
			$source_post_id,
			$taxonomy,
			array( 'fields' => 'ids' )
		);

		if ( ! is_wp_error( $term_ids ) ) {
			wp_set_object_terms( $new_post_id, $term_ids, $taxonomy, false );
		}
	}
}

/**
 * Copy post metadata, excluding edit-session metadata.
 *
 * @param int $source_post_id Source post ID.
 * @param int $new_post_id    New post ID.
 */
function ki_hiro_post_duplicator_copy_meta( $source_post_id, $new_post_id ) {
	$excluded_keys = array( '_edit_lock', '_edit_last', '_wp_old_slug' );
	$meta_keys     = get_post_custom_keys( $source_post_id );

	if ( empty( $meta_keys ) ) {
		return;
	}

	foreach ( $meta_keys as $meta_key ) {
		if ( in_array( $meta_key, $excluded_keys, true ) ) {
			continue;
		}

		$values = get_post_meta( $source_post_id, $meta_key, false );

		foreach ( $values as $value ) {
			add_post_meta( $new_post_id, $meta_key, $value );
		}
	}
}
