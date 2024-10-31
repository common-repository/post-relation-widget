<?php
/*
Plugin Name: Post Relation Widget
Description: Manage relations between posts depending on their category in a widget.
Author: Simeon Ackermann
Version: 0.14.01
Author URI: http://a-simeon.de
*/

/**
 * Adds Post Relation widget.
 */
class Post_Relation_Widget extends WP_Widget {

	protected static $options = array(
			"search_title"				=> "Post Relations - Search",
			"relation_title"			=> "My Relations",
			"default_relation_title"	=> "In relation with",
			"posts_table" 				=> "prw_posts",
			"relations_table"			=> "prw_relations",
		);

	public function __construct() {
		parent::__construct(
	 		'prw',
			'Post Relation Widget',
			array( 'description' => __( 'Manage relations between posts depending on their category in a widget.', 'prw' ), )
		);

		if ( is_active_widget( false, false, $this->id_base ) ) {
	        add_action('wp_enqueue_scripts', array($this, 'initScripts'));

	        $ajax_actions = array('ajax_search', 'ajax_output_all', 'add_relation', 'delete_relation');
	        foreach ($ajax_actions as $ajax_action) {
	        	add_action('wp_ajax_prw_' . $ajax_action, array($this, $ajax_action) );	        	
	        }

	        
		}	
		add_filter( 'plugin_action_links', array($this, 'add_action_links'), 10, 2 );	
		/*
		//optional global actions
		add_action( 'prw_output_relations', array($this, 'output_relations') );
		*/
		if( is_admin() ){
			if(isset($_REQUEST['page']) && $_REQUEST['page']=="prw-settings-admin"){
				add_action('admin_enqueue_scripts', array($this, 'initScripts'));
			}
			add_action('wp_ajax_prw_new_relation_section', array($this, 'ajax_admin_new_relation_section') );
			add_action('admin_menu', array($this, 'add_plugin_page'));
		}
		// install tables when creating new blog in multisite
		add_action( 'wpmu_new_blog', array($this, 'new_multisite')); 

		//shortcode for using widget
		add_shortcode( 'prw_widget', array($this, 'widget') );
	}

	function new_multisite($blog_id) {
		switch_to_blog( $blog_id );
		self::_install();
		$wpdb->query( $sql );

		restore_current_blog();
	}

	static function install($networkwide) {
		global $wpdb;
		if (function_exists('is_multisite') && is_multisite()) {
			// check if it is a network activation - if so, run the activation function for each blog id
			if ($networkwide) {
				$old_blog = $wpdb->blogid;
				// Get all blog ids
				$blogids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
				foreach ($blogids as $blog_id) {
					switch_to_blog($blog_id);
					self::_install();
				}
				switch_to_blog($old_blog);
				return;
			}   
		} 
		self::_install();		
	}

	static function _install() {
		global $wpdb;
		$relations_table = $wpdb->prefix . self::$options['relations_table'];
		$sql = "CREATE TABLE IF NOT EXISTS $relations_table (
			rel_id bigint(20) NOT NULL AUTO_INCREMENT,
			from_cat bigint(20) NOT NULL,
			to_cat bigint(20) NOT NULL,
			meta_value longtext,
			PRIMARY KEY (rel_id)
		);";
		$wpdb->query( $sql );

		$posts_table = $wpdb->prefix . self::$options['posts_table'];
		$sql = "CREATE TABLE IF NOT EXISTS $posts_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			rel_id bigint(20) NOT NULL,
			from_post bigint(20) NOT NULL,
			to_post bigint(20) NOT NULL,			
			status varchar(20) NOT NULL,
			author bigint(20) NOT NULL,
			time datetime NOT NULL,
			PRIMARY KEY (id),
			FOREIGN KEY (rel_id) REFERENCES $relations_table(rel_id)
		);";
		$wpdb->query( $sql );
	}

	function initScripts() {
		$options = array( 'ajaxurl' => admin_url('admin-ajax.php'), 'post_id' => '' );
		if ( is_single() ) {
			$options['post_id'] = get_the_ID();
		}
		wp_register_script( 'prw_script', plugins_url("/script.js" , __FILE__ ), array('jquery') );
		wp_enqueue_script( 'prw_script' );
		wp_localize_script('prw_script', 'prw_script', $options);

		wp_register_style( 'prw_style', plugins_url("/style.css" , __FILE__ ), array() );
		wp_enqueue_style( 'prw_style');
	}



function add_action_links( $links, $file ) {
	 if ( $file == plugin_basename(dirname(__FILE__) . '/post-relation-widget.php') )  {
	 	$in = '<a href="options-general.php?page=prw-settings-admin">' . __('Settings', 'prw') . '</a>';
	 	array_unshift($links, $in);
	 }	 
	return $links;

}

	/**
	 * Sanitize widget form values as they are saved.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = array_map("strip_tags", $new_instance);
		return $instance;
	}

	/**
	 * Back-end widget form.
	 */
	public function form( $instance ) {
		$instance = ($instance !== false) ? array_merge(self::$options, $instance) : self::$options;
		extract($instance); ?>
		<p>
			<label for="<?php echo $this->get_field_id( 'relation-title' ); ?>"><?php _e( 'Relation Title:', 'prw' ); ?></label> 
			<input class="widefat" id="<?php echo $this->get_field_id( 'relation-title' ); ?>" name="<?php echo $this->get_field_name( 'relation_title' ); ?>" type="text" value="<?php echo esc_attr( $relation_title ); ?>" />
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'search-title' ); ?>"><?php _e( 'Search Title:', 'prw' ); ?></label> 
			<input class="widefat" id="<?php echo $this->get_field_id( 'search-title' ); ?>" name="<?php echo $this->get_field_name( 'search_title' ); ?>" type="text" value="<?php echo esc_attr( $search_title ); ?>" />
		</p>		
		<?php 
	}

	/**
	 * Front-end display of widget.
	*/
	public function widget( $args, $instance ) {
		if ( ! is_single() )
			return;

		extract( $args );
		echo $before_widget;
		
		$id = get_the_ID();
		$relations = $this->get_relations( $id ); ?>			
		<div id="prw-ajax-msgs"></div>
		<div id="post-relation-widget-wrapper">					
			<?php $this->output_relations( $id, $relations ); ?>
			<a href="./#show-search" id="prw-show-search">[ add ]</a>
			<?php $this->output_search( $id, $relations, "" ); ?>			
		</div>			
		<?php echo $after_widget;
	}

	public function output_search( $id, $relations, $search ) { 
		if ( ! current_user_can( 'edit_post', $id ) )
			return;

		$options = get_option($this->option_name)[$this->number];
		$search_title = apply_filters( 'widget_title', $options['search_title'] );		
		echo '<div id="prw-search-wrapper">';
			echo ! empty( $search_title ) ? '<h3 class="widget-title">'. $search_title .'</h3>' : ''; ?>
			<form action="" method="get">
				<input type="search" id="prw-search" placeholder="<?php _e('Search'); ?>..." autocomplete="off" />
				<!--
				<div id="post-relation-widget-loader"><img src="<?php echo plugins_url('/images/loader.gif' , __FILE__ ); ?>" alt="loading..." /></div>
			-->
			</form>
			<div id="prw-search-results">
				<?php $this->output_search_list( $id, $relations, $search ); ?>
			</div>
		</div>
		<?php
	}
	public function output_search_list( $id, $relations, $search ) {
		$relations = empty($relations) ? $this->get_relations( $id ) : $relations ;
		$rel_cats = array();
		$rel_posts = array();
		foreach ($relations as $relation) {
			$rel_cats[] = $relation->rel_cat;
			foreach ($relation->posts as $post) {
				$rel_posts[] = $post->rel_post;
			}
		}

		global $wpdb;
		$sql = "SELECT $wpdb->posts.*, $wpdb->terms.term_id
			FROM $wpdb->posts LEFT JOIN $wpdb->term_relationships ON ($wpdb->posts.ID = $wpdb->term_relationships.object_id)
			LEFT JOIN $wpdb->term_taxonomy ON ($wpdb->term_relationships.term_taxonomy_id = $wpdb->term_taxonomy.term_taxonomy_id)
			LEFT JOIN $wpdb->terms ON ($wpdb->term_taxonomy.term_id = $wpdb->terms.term_id)
			WHERE $wpdb->term_taxonomy.taxonomy = 'category'";
		$sql .= empty($rel_cats) ? "" : " AND $wpdb->terms.term_id IN (" . implode(',', $rel_cats) . ")";
		$sql .= empty($rel_posts) ? "" : " AND $wpdb->posts.ID NOT IN (" . implode(',', $rel_posts) . ")";
		$sql .= empty($search) ? "" : " AND ($wpdb->posts.post_title LIKE '%{$search}%' OR $wpdb->posts.post_content LIKE '%{$search}%')";
		$sql .= " AND $wpdb->posts.post_type = 'post'
			AND $wpdb->posts.post_status = 'publish' 
			AND $wpdb->posts.post_date < NOW()
			ORDER BY $wpdb->terms.term_id, $wpdb->posts.post_date DESC
			LIMIT 0, 10";
		$myrows = $wpdb->get_results($sql);
		$search_posts = array();
		foreach ($myrows as $post) {
			$search_posts[$post->term_id]->posts[] = $post;
		}
		global $post;
		foreach ($relations as $relation) {
			//echo "<h4>Add " . $relation->title . "</h4>";
			//echo "<h4 class='widget-title'><img src='" . plugins_url('/images/add.png' , __FILE__ ) . "' /> " . (get_category($relation->rel_cat)->name) . "</h4>";
			echo "<h4 class='widget-title'>" . (get_category($relation->rel_cat)->name) . "</h4>";
			echo '<ul prw_relation_ID="' . $relation->rel_id . '">';
				if ( isset($search_posts[$relation->rel_cat]) ) {
					foreach ($search_posts[$relation->rel_cat]->posts as $post) {
						$wpost = get_post( $post->ID );
						echo '<li class="prw-add-relation" prw_post_ID="' . $wpost->ID . '"><a href="' . $wpost->guid . '">' . $wpost->post_title . '</a></li>';
					}
				} else {
					echo '<li><i>No posts found.</i></li>';
				}
			echo '</ul>';
		}
		echo empty($relations) ? '<ul><li><i>No relations found.</i></li></ul>' : '';
	}
	function ajax_search() {
		$vals = $this->get_post_int_entities( array( "post_id" => $_POST['post_id']) );		
		$this->output_search_list( $vals['post_id'], "", $_POST['data'] );
		die();
	}	

	public function output_relations( $id, $relations ) {
		$relations = empty($relations) ? $this->get_relations( $id ) : $relations ;
		$options = get_option($this->option_name)[$this->number];
		$relation_title = apply_filters( 'widget_title', $options['relation_title'] );
		
		echo '<div id="prw-relations">';		
		echo !empty($relation_title) ? '<h3 class="widget-title">'. $relation_title .'</h3>' : '';
		global $post;
		foreach ($relations as $relation) {
			echo "<h4 class='widget-title'>" . $relation->title . "</h4>";			
			echo '<ul prw_relation_ID="' . $relation->rel_id . '">';								
				foreach ($relation->posts as $post) {
					$wpost = get_post( $post->rel_post );
					$class = ( current_user_can( 'edit_post', $id ) && current_user_can( 'edit_post', $wpost->ID ) ) ? 'prw-delete-relation' : '';
					echo '<li class="' . $class . '" prw_post_ID="' . $wpost->ID . '" prw_ID="' . $post->id . '"><a href="' . $wpost->guid . '">' . $wpost->post_title . '</a></li>';
				}
				echo empty($relation->posts) ? '<li><i>No posts found.</i></li>' : '';
			echo "</ul>";
		}
		echo empty($relations) ? '<ul><li><i>No relations found.</i></li></ul>' : '';
		
		echo '</div>';
	}

	function add_relation() {	
		$vals = $this->get_post_int_entities( array( "post_id" => $_POST['post_id'], "rel_post" => $_POST['data']['rel_post'], "relation" => $_POST['data']['relation']) );
		extract($vals);
		
		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'edit_post', $rel_post ) )
			die('User not allowed.');

		global $wpdb;
		$posts_table = $wpdb->prefix . self::$options['posts_table'];
		$wpdb->insert( $posts_table, array(
			'rel_id' => $relation,
			'from_post' => $post_id,
			'to_post' => $rel_post,
			'status' => "publish",
			'author' => get_current_user_id(),
			'time' => date('Y-m-d H:i:s')
			)			
		);
		die();
	}

	function delete_relation() {
		$vals = $this->get_post_int_entities( array("id" => $_POST['data']['id'], "post_id" => $_POST['post_id'], "rel_post" => $_POST['data']['rel_post'], "relation" => $_POST['data']['relation']) );
		extract($vals);
		
		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'edit_post', $rel_post ) )
			die('User not allowed.');

		global $wpdb;
		$posts_table = $wpdb->prefix . self::$options['posts_table'];
		/*$wpdb->update(
			$posts_table,
			array(
				'status' => 'trash',
				'author' => get_current_user_id(),
				'time' => date('Y-m-d H:i:s')
			),
			array( 'id' => $id )
		);*/
		$wpdb->insert( $posts_table, array(
			'rel_id' => $relation,
			'from_post' => $post_id,
			'to_post' => $rel_post,
			'status' => "trash",
			'author' => get_current_user_id(),
			'time' => date('Y-m-d H:i:s')
			)			
		);
		$newid = $wpdb->insert_id;
		$wpdb->update(
			$posts_table,
			array(
				'status' => $newid . '-published',
			),
			array( 'id' => $id )
		);
		die();
	}

	function ajax_output_all() {
		$vals = $this->get_post_int_entities( array( "post_id" => $_POST['post_id']) );
		$relations = $this->get_relations( $vals['post_id'] );
		$this->output_relations( $vals['post_id'], $relations );
		$this->output_search( $vals['post_id'], $relations, "" );		
		die();
	}

	function get_post_int_entities( $entities ) {
		$result = array();
		foreach ($entities as $key => $entity) {
			if ( isset($entity) && ! empty($entity) && is_numeric($entity) )
				$result[$key] = intval($entity);
			else die('What about the ' . $key . '?');
		}		
		return $result;
	}
	/* function check_post_entities( $entities ) {
		foreach ($entities as $entity) {			
			if ( !isset($entity) || empty($entity) ) {
				die('Ups, I have a problem with a POST value.'); //die('What about the ' . $entity . '?');
			}
		}
	}*/

	function get_relations( $id ) {
		$my_cids = array();
		foreach (get_the_category( $id ) as $my_category)
			$my_cids[] = $my_category->cat_ID;		

		global $wpdb;
		$posts_table = $wpdb->prefix . self::$options['posts_table'];
		$relations_table = $wpdb->prefix . self::$options['relations_table'];
		$sql = "SELECT * 
			FROM (SELECT * 
					FROM $posts_table
					WHERE (from_post = $id OR to_post = $id)
					AND status = 'publish'
				) AS $posts_table
			RIGHT JOIN $relations_table ON ($posts_table.rel_id = $relations_table.rel_id)
			WHERE ( from_cat IN (" . implode(',', $my_cids) . ") OR to_cat IN (" . implode(',', $my_cids) . ") )
			ORDER BY $relations_table.rel_id";
		$myrows = (!empty($my_cids)) ? $wpdb->get_results( $sql ) : array();
		$result = array();
		foreach ($myrows as $myrow) {
			//create new relation entry if not already in array
			if ( ! array_key_exists($myrow->rel_id, $result) ) {
				if ( false === ($meta_value = unserialize($myrow->meta_value)) ) {
					//default meta values
					$title = self::$options['default_relation_title'];
				} else {
					//set unserialized meta values
					$title = in_array($myrow->from_cat, $my_cids) ? $meta_value['from_title'] : $meta_value['to_title'];
				}
				$result[$myrow->rel_id] = (object) array(
					'rel_id' => $myrow->rel_id,
					'title' => $title,
					'rel_cat' => in_array($myrow->from_cat, $my_cids) ? $myrow->to_cat : $myrow->from_cat,
				);
			}
			//fill posts-array if exists. Otherwise their are empty 
			if ( $myrow->from_post != NULL ) {
				$result[$myrow->rel_id]->posts[] = (object) array(
					'id' => $myrow->id,
					'rel_post' => $id ==  $myrow->from_post ? $myrow->to_post : $myrow->from_post,
				);
			} else {
				$result[$myrow->rel_id]->posts = array();
			}		
		}
		return $result;
	}

	public function add_plugin_page(){
        add_options_page('Post Relation Widget Settings', 'Post Relation Widget', 'manage_options', 'prw-settings-admin', array($this, 'create_admin_page'));
    }
    public function create_admin_page(){
    	if ( !empty($_POST) ) {
			$this->save_admin_settings();
		}
        ?>
		<div class="wrap">
		    <?php screen_icon(); ?>
		    <h2>Post Relation Widget Settings</h2>			
		    <form method="post" action="">
		    	<?php  	
		    	global $wpdb;
		    	$relations_table = $wpdb->prefix . self::$options['relations_table'];
		    	$sql = "SELECT * FROM $relations_table ORDER BY rel_id";
				$myrows = $wpdb->get_results( $sql );
				$id = 0;
				//if ( ! empty($myrows) ) {
					?>
					<h3>Relations</h3>
					<table class="widefat" style="width: 600px">
						<thead>
							<tr>
								<th><?php _e('From Category'); ?></th>
								<th><?php _e('To Category'); ?></th>
								<th><?php _e('From Title'); ?></th>
								<th><?php _e('To Title'); ?></th>
							</tr>
						</thead>
						<tbody id="prw-relations-table">							
						<?php	
						foreach ($myrows as $relation) {
							$id = $relation->rel_id;
							$this->relation_settings_section( $id , $relation );
						}
						?>
						</tbody>
					</table>
					<?php
				//}
				echo '<p><a href="#" class="add-new-h2" id="prw-admin-create-relation" prw_relation_ID="'. ++$id .'">Create new Relation</a></p>';
		    	wp_nonce_field( 'name_of_my_action','name_of_nonce_field' );
		    	submit_button(); ?>
		    </form>
		</div>
		<?php		
    }
    public function relation_settings_section( $id, $relation ) {
    	if ( false === ($meta_value = unserialize($relation->meta_value)) ) {
			$from_title = ( $to_title = self::$options['default_relation_title'] );
		} else {
			$from_title = $meta_value['from_title'];
			$to_title = $meta_value['to_title'];
		}
		$categories=get_categories( array( 'orderby' => 'name', 'order' => 'ASC' ) );
    	?>
    	<tr>
    		<td><select name="widget-prw[relations][<?php echo $id; ?>][from_cat]">
				<?php foreach ($categories as $category) { ?>
					<option value="<?php echo $category->cat_ID; ?>" <?php selected( $relation->from_cat, $category->cat_ID ); ?>><?php echo $category->name; ?></option>
				<?php } ?>
				</select>
			</td>
			<td><select name="widget-prw[relations][<?php echo $id; ?>][to_cat]">
				<?php foreach ($categories as $category) { ?>
					<option value="<?php echo $category->cat_ID; ?>" <?php selected( $relation->to_cat, $category->cat_ID ); ?>><?php echo $category->name; ?></option>
				<?php } ?>
				</select>
			</td>
			<td><input type="text" name="widget-prw[relations][<?php echo $id; ?>][from_title]" value="<?php echo $from_title; ?>" /></td>			
			<td><input type="text" name="widget-prw[relations][<?php echo $id; ?>][to_title]" value="<?php echo $to_title; ?>" /></td>
		</tr>
    	<?php
    }
    public function ajax_admin_new_relation_section() {
    	$id = intval( $_POST['data'] );
    	$meta_value = serialize( array( 'from_title' => '', 'to_title' => '' ) );
    	$relation = (object) array(
    		'from_cat' => '',
    		'to_cat' => '',
    		'meta_value' => $meta_value 		
    		);
    	$this->relation_settings_section( $id, $relation );
    	die();
    }

    public function save_admin_settings() {
    	check_admin_referer( 'name_of_my_action', 'name_of_nonce_field' );    	

    	global $wpdb;
    	$relations_table = $wpdb->prefix . self::$options['relations_table'];
    	$relations = $_POST['widget-prw']['relations'];
    	foreach ($relations as $id => $relation) {
    		$from_cat = intval($relation['from_cat']);
    		$to_cat = intval($relation['to_cat']);
    		$meta_value = serialize( array( 
    			'from_title' => stripcslashes( $relation['from_title'] ), 
    			'to_title' => stripcslashes( $relation['to_title'] )
    		) );
    		$this_relation = $wpdb->get_results( "SELECT * FROM $relations_table WHERE rel_id = $id" );
			if ( ! empty( $this_relation ) ) {
				$wpdb->update( $relations_table,
					array(
						'from_cat'	=> $from_cat,
						'to_cat'	=> $to_cat,
						'meta_value'=> $meta_value,
					),
					array( 'rel_id' => $id ) 
				);
			} else {
				$wpdb->insert( $relations_table, array(
					'from_cat' => $from_cat,
					'to_cat' => $to_cat,
					'meta_value'=> $meta_value,
					)			
				);
			}    		
    	}
    }


}

add_action( 'widgets_init', create_function( '', 'register_widget( "post_relation_widget" );' ) );
register_activation_hook( __FILE__, array('post_relation_widget', 'install') );
?>