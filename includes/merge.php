<?php
/**
 * Main class for mering a fork back into its parent
 * @package fork
 */
 
class Fork_Merge {
	
	public $ttl = 1; //super-short TTL means we cache within page load, but don't ever hit persistant cache
	
	function __construct( &$parent ) {
		
		$this->parent = &$parent;
		
		//fire up the native WordPress diff engine
		$engine = extension_loaded( 'xdiff' ) ? 'xdiff' : 'native';
		require_once ABSPATH . WPINC . '/wp-diff.php';
        require_once ABSPATH . WPINC . '/Text/Diff/Engine/' . $engine . '.php';

		//init our three-way diff library which extends WordPress's native diff library
		if ( !class_exists( 'Text_Diff3' ) )
			require_once dirname( __FILE__ ) . '/diff3.php';	
	
		add_filter( 'wp_insert_post_data', array( $this, 'check_merge_conflict' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'conflict_warning' ) );
		add_action( 'transition_post_status', array( $this, 'intercept_publish' ), 10, 3 );

	}
	
	/**
	 * Merges a fork's content back into its parent post
	 * @param int $fork_id the ID of the fork to merge
	 */
	function merge( $fork_id ) {
			
		$update = array( 
			'ID' => get_post( $fork_id )->post_parent,
			'post_content' => $this->get_merged( $fork_id ),
		);

		return wp_update_post( $update );
		
	}
	
	/**
	 * Returns the merged, possibly conflicted post_content
	 * @param int|object $fork_id the ID of the fork, or the fork itself
	 * @return string the post content
	 * NOTE: may be conflicted. use `is_conflicted()` to check
	 */
	function get_merged( $fork ) {
		
		$diff = $this->get_diff( $fork );
		$merged = $diff->mergedOutput( __( 'Fork', 'fork' ), __( 'Current Version', 'fork' ) );
		return implode( "\n", $merged );
		
	}
		
	/**
	 * Determines if a given fork can merge cleanly into the current post
	 * @param int|object $fork_id the ID for the fork to merge or the fork itself
	 * @return bool true if conflicted, otherwise false
	 */
	function is_conflicted( $fork ) {
		
		$diff = $this->get_diff( $fork );
		
		foreach ( $diff->_lines as $line )
			if ( $line->isConflict() )
				return true;
		
		return false;
			
	}
	
	/**
	 * Performs a three-way merge with a fork, it's parent revision, and the current version of the post
	 * Caches so that multiple calls to get_diff within the same page load will not re-run the diff each time
	 * Passing an object (rather than a fork id) will bypass the cache (to allow for on-the-fly diffing on save
	 */
	function get_diff( $fork ) {
	
		if ( !is_object( $fork ) && $diff = wp_cache_get( $fork, 'Fork_Diff' ) )
			return $diff;
	
		if ( !is_object( $fork ) )
			$fork = get_post( $fork );
		
		//grab the three elments
		$parent = $this->parent->revisions->get_parent_revision( $fork );
		$current = $fork->post_parent;
				
		//normalize whitespace and convert string -> array
		foreach ( array( 'fork', 'parent', 'current' ) as $string ) {
			$$string = get_post( $$string )->post_content;
			$$string = normalize_whitespace( $$string );
			$$string = explode( "\n", $$string );
		}
		
		//diff, cache, return
		$diff = new Text_Diff3( $parent, $fork, $current );
		wp_cache_set( $fork->ID, $diff, 'Fork_Diff', $this->ttl );
		return $diff;
	
	}	
	
	/**
	 * Prior to publishing, check if the post fork is conflicted
	 * If so, intercept the publish, mark the conflict, and revert to draft
	 */
	function check_merge_conflict( $post, $postarr ) {
		
		//verify post type
		if ( $post['post_type'] != 'fork' )
			return $post;
			
		//not an update
		if ( !isset( $postarr['ID'] ) )
			return $post;
	
		//not publishing
		if ( $post['post_status'] != 'publish' )
			return $post;
		
		//not conflicted, no need to do anything here, let the merge go through
		if ( !$this->is_conflicted( (object) $postarr ) )
			return $post;
			
		$post['post_content'] = $this->get_merged( (object) $postarr );
		$post['post_status'] = 'draft';
		
		return $post;
		
	}
	
	function conflict_warning() {
		global $post;
		
		if ( !$post )
			return;
			
		if ( get_post_type( $post ) != 'fork' )
			return;
			
		if ( !$this->is_conflicted( $post->ID ) )
			return;
		
		$this->parent->template( 'conflict-warning' );
		
	}	
	
	/**
	 * Intercept the publish action and merge forks into their parent posts
	 */
	function intercept_publish( $new, $old, $post ) {
	
		if ( wp_is_post_revision( $post ) )
			return;
									
		if ( $post->post_type != 'fork' )
			return;
			
		if ( $new != 'publish' )
			return;
					
		$post = $this->merge( $post->ID );
		wp_safe_redirect( admin_url( "post.php?action=edit&post={$post}&message=6" ) );
		exit();
		
	}
	
}