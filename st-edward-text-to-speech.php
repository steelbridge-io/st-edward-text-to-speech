<?php
/**
 * Plugin Name: Saint Edward Text To Speech
 * Plugin URI: https://example.com/plugins/wp-text-to-speech
 * Description: Adds text-to-speech functionality to WordPress posts and pages
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * Text Domain: wp-text-to-speech
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

class WP_Text_To_Speech {

	public function __construct() {
		// Register scripts and styles
		add_action('wp_enqueue_scripts', array($this, 'register_scripts'));

		// Add the player automatically to single posts/pages
		add_filter('the_content', array($this, 'maybe_add_tts_player'));

		// Add settings page
		add_action('admin_menu', array($this, 'add_settings_page'));
		add_action('admin_init', array($this, 'register_settings'));

		// Add meta box for individual post/page settings
		add_action('add_meta_boxes', array($this, 'add_tts_meta_box'));
		add_action('save_post', array($this, 'save_tts_meta_box_data'));

		// Add this line to register the AJAX handler
		add_action('wp_ajax_wp_tts_search_posts', array($this, 'ajax_search_posts'));

	}

	/**
	 * Register scripts and styles
	 */
	public function register_scripts() {
		// Register scripts
		wp_register_script(
			'text-to-speech-js',
			plugin_dir_url(__FILE__) . 'js/text-to-speech.js',
			array('jquery'),
			'1.0.0',
			true
		);

		wp_register_style(
			'text-to-speech-css',
			plugin_dir_url(__FILE__) . 'css/text-to-speech.css',
			array(),
			'1.0.0'
		);
	}

	/**
	 * Maybe add the TTS player to content
	 *
	 * @param string $content The post content
	 * @return string The content with the TTS player added
	 */
	public function maybe_add_tts_player($content) {
		// Only add to single posts/pages
		if (!is_singular() || !in_the_loop() || !is_main_query()) {
			return $content;
		}

		$post_id = get_the_ID();

		// Get global options
		$options = get_option('wp_tts_options', array(
			'post_types' => array('post', 'page'),
			'position' => 'before',
			'include_mode' => 'post_types', // 'post_types', 'specific', or 'exclude'
			'specific_posts' => array(),
			'exclude_posts' => array()
		));

		$post_type = get_post_type();

		// Check if this post should have TTS based on the selection mode
		$should_add_tts = false;

		// First check individual post setting (this overrides global settings)
		$individual_setting = get_post_meta($post_id, '_wp_tts_enable', true);
		if ($individual_setting === 'yes') {
			$should_add_tts = true;
		} elseif ($individual_setting === 'no') {
			$should_add_tts = false;
		} else {
			// No individual setting, check global settings
			switch ($options['include_mode']) {
				case 'post_types':
					// Add TTS based on post type
					$should_add_tts = in_array($post_type, (array)$options['post_types']);
					break;

				case 'specific':
					// Add TTS only to specific posts/pages
					$should_add_tts = in_array($post_id, (array)$options['specific_posts']);
					break;

				case 'exclude':
					// Add TTS to all post types except excluded posts
					$should_add_tts = in_array($post_type, (array)$options['post_types']) &&
					                  !in_array($post_id, (array)$options['exclude_posts']);
					break;
			}
		}

		// Return original content if we shouldn't add TTS
		if (!$should_add_tts) {
			return $content;
		}

		// Enqueue assets
		wp_enqueue_script('text-to-speech-js');
		wp_enqueue_style('text-to-speech-css');

		// Get clean text for TTS
		$clean_content = $this->get_clean_content($content);

		// Pass data to JavaScript
		wp_localize_script(
			'text-to-speech-js',
			'wpTtsData',
			array(
				'post_id' => $post_id,
				'post_content' => $clean_content,
				'nonce' => wp_create_nonce('wp_tts_nonce'),
				'ajaxurl' => admin_url('admin-ajax.php')
			)
		);

		// Generate player HTML
		$player_html = $this->generate_player_html();

		// Add player to content based on position setting
		if ($options['position'] === 'after') {
			return $content . $player_html;
		} else {
			return $player_html . $content;
		}
	}

	/**
	 * Clean content for TTS
	 *
	 * @param string $content Raw content
	 * @return string Cleaned content
	 */
	private function get_clean_content($content) {
		// Get raw content without HTML
		$clean_content = wp_strip_all_tags($content);

		// Clean up whitespace and limit length
		$clean_content = preg_replace('/\s+/', ' ', $clean_content);
		$clean_content = trim($clean_content);

		// Limit to 10K chars to avoid performance issues
		$clean_content = substr($clean_content, 0, 10000);

		return $clean_content;
	}

	/**
	 * Generate the HTML for the TTS player
	 */
	private function generate_player_html() {
		ob_start();
		?>
        <h5 class="mt-5">Text To Speech Player</h5>
        <div class="wp-tts-player">
            <div class="wp-tts-controls">
                <button class="wp-tts-play-button" aria-label="Play text">
                    <span class="wp-tts-play-icon">▶</span>
                    <span class="wp-tts-pause-icon" style="display:none;">⏸</span>
                </button>
                <div class="wp-tts-progress-container">
                    <div class="wp-tts-progress-bar"></div>
                </div>
                <div class="wp-tts-time">
                    <span class="wp-tts-current-time">0:00</span> /
                    <span class="wp-tts-duration">0:00</span>
                </div>
                <button class="wp-tts-stop-button" aria-label="Stop">⏹</button>
                <select class="wp-tts-voice-select">
                    <option value="default">Default Voice</option>
                </select>
            </div>
            <div class="wp-tts-status">Ready</div>
        </div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Add settings page to admin menu
	 */
	public function add_settings_page() {
		add_options_page(
			'Text to Speech Settings',
			'Text to Speech',
			'manage_options',
			'wp-text-to-speech',
			array($this, 'render_settings_page')
		);
	}

	/**
	 * Register plugin settings
	 */
	public function register_settings() {
		register_setting(
			'wp_tts_options',
			'wp_tts_options',
			array($this, 'validate_options')
		);

		add_settings_section(
			'wp_tts_general_settings',
			'General Settings',
			array($this, 'general_settings_callback'),
			'wp-text-to-speech'
		);

		add_settings_field(
			'include_mode',
			'Content Selection Mode',
			array($this, 'include_mode_callback'),
			'wp-text-to-speech',
			'wp_tts_general_settings'
		);

		add_settings_field(
			'post_types',
			'Select Post Types',
			array($this, 'post_types_callback'),
			'wp-text-to-speech',
			'wp_tts_general_settings'
		);

		add_settings_field(
			'specific_posts',
			'Select Specific Posts/Pages',
			array($this, 'specific_posts_callback'),
			'wp-text-to-speech',
			'wp_tts_general_settings'
		);

		add_settings_field(
			'exclude_posts',
			'Exclude Specific Posts/Pages',
			array($this, 'exclude_posts_callback'),
			'wp-text-to-speech',
			'wp_tts_general_settings'
		);

		add_settings_field(
			'position',
			'Player Position',
			array($this, 'position_callback'),
			'wp-text-to-speech',
			'wp_tts_general_settings'
		);
	}

	/**
	 * Validate plugin options
	 */
	public function validate_options($input) {
		$valid = array();

		// Include mode
		$valid['include_mode'] = isset($input['include_mode']) ?
			sanitize_text_field($input['include_mode']) : 'post_types';

		// Post types
		$valid['post_types'] = isset($input['post_types']) ?
			array_map('sanitize_text_field', (array)$input['post_types']) : array();

		// Position
		$valid['position'] = isset($input['position']) ?
			sanitize_text_field($input['position']) : 'before';

		// Specific posts
		$valid['specific_posts'] = isset($input['specific_posts']) ?
			array_map('absint', (array)$input['specific_posts']) : array();

		// Exclude posts
		$valid['exclude_posts'] = isset($input['exclude_posts']) ?
			array_map('absint', (array)$input['exclude_posts']) : array();

		return $valid;
	}

	/**
	 * Display settings section description
	 */
	public function general_settings_callback() {
		echo '<p>Configure your Text to Speech settings.</p>';
	}

	/**
	 * Render include mode setting field
	 */
	public function include_mode_callback() {
		$options = get_option('wp_tts_options', array(
			'include_mode' => 'post_types',
		));

		$modes = array(
			'post_types' => 'Add to all selected post types',
			'specific' => 'Add only to specific posts/pages',
			'exclude' => 'Add to all selected post types except excluded posts/pages'
		);

		echo '<div class="include-mode-selector">';
		foreach ($modes as $value => $label) {
			$checked = ($options['include_mode'] === $value) ? 'checked' : '';
			echo '<label>';
			echo '<input type="radio" name="wp_tts_options[include_mode]" value="' . esc_attr($value) . '" ' . $checked . ' class="include-mode-radio"> ';
			echo esc_html($label);
			echo '</label><br>';
		}
		echo '</div>';

		// Add JavaScript to toggle visibility of related sections
		// In your settings page rendering function:
		$tts_nonce = wp_create_nonce('wp_tts_nonce');
		?>
        <script>
            jQuery(document).ready(function($) {
                // Pass the nonce to your JavaScript
                var ttsNonce = '<?php echo esc_js($tts_nonce); ?>';

                // Use the nonce in your AJAX calls
                $('.post-search').on('input', function() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'GET',
                        data: {
                            action: 'wp_tts_search_posts',
                            query: $(this).val(),
                            nonce: ttsNonce
                        },
                        success: function(response) {
                            // Handle response
                        }
                    });
                });
            });
        </script>
		<?php
		?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Initial state
                function updateVisibility() {
                    var mode = $('input[name="wp_tts_options[include_mode]"]:checked').val();

                    if (mode === 'post_types' || mode === 'exclude') {
                        $('.setting-post-types').show();
                    } else {
                        $('.setting-post-types').hide();
                    }

                    if (mode === 'specific') {
                        $('.setting-specific-posts').show();
                    } else {
                        $('.setting-specific-posts').hide();
                    }

                    if (mode === 'exclude') {
                        $('.setting-exclude-posts').show();
                    } else {
                        $('.setting-exclude-posts').hide();
                    }
                }

                // Update on change
                $('.include-mode-radio').on('change', function() {
                    updateVisibility();
                });

                // Initial update
                updateVisibility();
            });
        </script>
		<?php
	}

	/**
	 * Render post types setting field
	 */
	public function post_types_callback() {
		$options = get_option('wp_tts_options', array(
			'post_types' => array('post', 'page'),
		));

		$post_types = get_post_types(array('public' => true), 'objects');

		echo '<div class="setting-post-types">';
		foreach ($post_types as $post_type) {
			$checked = in_array($post_type->name, (array)$options['post_types']) ? 'checked' : '';
			echo '<label>';
			echo '<input type="checkbox" name="wp_tts_options[post_types][]" value="' . esc_attr($post_type->name) . '" ' . $checked . '> ';
			echo esc_html($post_type->labels->singular_name);
			echo '</label><br>';
		}
		echo '</div>';
	}

	/**
	 * Render specific posts setting field
	 */
	public function specific_posts_callback() {
		$options = get_option('wp_tts_options', array(
			'specific_posts' => array(),
		));

		echo '<div class="setting-specific-posts">';
		echo '<p>Select specific posts or pages where you want to add the text-to-speech player.</p>';

		// Add post selection UI with search
		?>
        <div class="post-selector">
            <input type="text" id="post-search" placeholder="Search for posts or pages..." class="widefat" style="margin-bottom: 10px;">
            <select id="post-search-results" size="5" style="width: 100%; margin-bottom: 10px;">
                <option value="">Search for posts/pages above</option>
            </select>
            <button type="button" id="add-selected-post" class="button">Add Selected</button>
        </div>

        <div class="selected-posts" style="margin-top: 15px;">
            <h4>Selected Posts and Pages</h4>
            <ul id="selected-posts-list" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
				<?php
				if (!empty($options['specific_posts'])) {
					foreach ($options['specific_posts'] as $post_id) {
						$post_title = get_the_title($post_id);
						if (!empty($post_title)) {
							echo '<li>';
							echo '<input type="hidden" name="wp_tts_options[specific_posts][]" value="' . esc_attr($post_id) . '">';
							echo esc_html($post_title) . ' (ID: ' . esc_html($post_id) . ')';
							echo ' <button type="button" class="remove-post button-link" data-id="' . esc_attr($post_id) . '">Remove</button>';
							echo '</li>';
						}
					}
				} else {
					echo '<li class="no-posts-message">No posts selected</li>';
				}
				?>
            </ul>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var searchTimeout;

                // Perform search
                $('#post-search').on('keyup', function() {
                    var query = $(this).val();

                    clearTimeout(searchTimeout);

                    if (query.length < 3) {
                        $('#post-search-results').html('<option value="">Type at least 3 characters to search</option>');
                        return;
                    }

                    searchTimeout = setTimeout(function() {
                        $.ajax({
                            url: ajaxurl,
                            data: {
                                'action': 'wp_tts_search_posts',
                                'query': query,
                                'nonce': '<?php echo wp_create_nonce('wp_tts_search_nonce'); ?>'
                            },
                            success: function(response) {
                                $('#post-search-results').empty();

                                if (response.success && response.data.length > 0) {
                                    $.each(response.data, function(i, post) {
                                        $('#post-search-results').append(
                                            $('<option>', {
                                                value: post.id,
                                                text: post.title + ' (' + post.type + ', ID: ' + post.id + ')'
                                            })
                                        );
                                    });
                                } else {
                                    $('#post-search-results').append(
                                        $('<option>', {
                                            value: '',
                                            text: 'No posts found'
                                        })
                                    );
                                }
                            }
                        });
                    }, 500);
                });

                // Add selected post to the list
                $('#add-selected-post').on('click', function() {
                    var selectedOption = $('#post-search-results option:selected');
                    var postId = selectedOption.val();
                    var postTitle = selectedOption.text();

                    if (postId) {
                        // Check if already added
                        if ($('#selected-posts-list input[value="' + postId + '"]').length === 0) {
                            $('.no-posts-message').remove();

                            $('#selected-posts-list').append(
                                $('<li>', {
                                    html: '<input type="hidden" name="wp_tts_options[specific_posts][]" value="' + postId + '">' +
                                        postTitle +
                                        ' <button type="button" class="remove-post button-link" data-id="' + postId + '">Remove</button>'
                                })
                            );
                        }
                    }
                });

                // Remove post from list
                $(document).on('click', '.remove-post', function() {
                    var listItem = $(this).closest('li');
                    listItem.remove();

                    if ($('#selected-posts-list li').length === 0) {
                        $('#selected-posts-list').append(
                            '<li class="no-posts-message">No posts selected</li>'
                        );
                    }
                });
            });
        </script>
		<?php
		echo '</div>';

		// Register AJAX handler for post search
		add_action('wp_ajax_wp_tts_search_posts', array($this, 'ajax_search_posts'));
	}

	/**
	 * AJAX handler for searching posts
	 */
	public function ajax_search_posts() {
		// Check if the request is coming from admin
		if (!current_user_can('edit_posts')) {
			wp_send_json_error('Unauthorized access', 403);
			return;
		}

		// More permissive nonce check for debugging
		// Don't use this in production without proper nonce validation!

		$query = isset($_REQUEST['query']) ? sanitize_text_field($_REQUEST['query']) : '';

		$results = array();

		if (!empty($query)) {
			$args = array(
				'post_type'      => array('post', 'page'),
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				's'              => $query
			);

			$search_results = new WP_Query($args);

			if ($search_results->have_posts()) {
				while ($search_results->have_posts()) {
					$search_results->the_post();
					$results[] = array(
						'id'    => get_the_ID(),
						'title' => get_the_title(),
						'type'  => get_post_type()
					);
				}
				wp_reset_postdata();
			}
		}

		wp_send_json_success($results);
	}
	/**
	 * Render exclude posts setting field
	 */
	public function exclude_posts_callback() {
		$options = get_option('wp_tts_options', array(
			'exclude_posts' => array(),
		));

		echo '<div class="setting-exclude-posts">';
		echo '<p>Select specific posts or pages where you want to <strong>exclude</strong> the text-to-speech player.</p>';

		// Add post selection UI with search (similar to specific posts)
		?>
        <div class="post-selector">
            <input type="text" id="exclude-post-search" placeholder="Search for posts or pages to exclude..." class="widefat" style="margin-bottom: 10px;">
            <select id="exclude-post-search-results" size="5" style="width: 100%; margin-bottom: 10px;">
                <option value="">Search for posts/pages above</option>
            </select>
            <button type="button" id="add-exclude-post" class="button">Add to Exclusion List</button>
        </div>

        <div class="excluded-posts" style="margin-top: 15px;">
            <h4>Excluded Posts and Pages</h4>
            <ul id="excluded-posts-list" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
				<?php
				if (!empty($options['exclude_posts'])) {
					foreach ($options['exclude_posts'] as $post_id) {
						$post_title = get_the_title($post_id);
						if (!empty($post_title)) {
							echo '<li>';
							echo '<input type="hidden" name="wp_tts_options[exclude_posts][]" value="' . esc_attr($post_id) . '">';
							echo esc_html($post_title) . ' (ID: ' . esc_html($post_id) . ')';
							echo ' <button type="button" class="remove-exclude-post button-link" data-id="' . esc_attr($post_id) . '">Remove</button>';
							echo '</li>';
						}
					}
				} else {
					echo '<li class="no-excluded-posts-message">No posts excluded</li>';
				}
				?>
            </ul>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var excludeSearchTimeout;

                // Perform search
                $('#exclude-post-search').on('keyup', function() {
                    var query = $(this).val();

                    clearTimeout(excludeSearchTimeout);

                    if (query.length < 3) {
                        $('#exclude-post-search-results').html('<option value="">Type at least 3 characters to search</option>');
                        return;
                    }

                    excludeSearchTimeout = setTimeout(function() {
                        $.ajax({
                            url: ajaxurl,
                            data: {
                                'action': 'wp_tts_search_posts',
                                'query': query,
                                'nonce': '<?php echo wp_create_nonce('wp_tts_search_nonce'); ?>'
                            },
                            success: function(response) {
                                $('#exclude-post-search-results').empty();

                                if (response.success && response.data.length > 0) {
                                    $.each(response.data, function(i, post) {
                                        $('#exclude-post-search-results').append(
                                            $('<option>', {
                                                value: post.id,
                                                text: post.title + ' (' + post.type + ', ID: ' + post.id + ')'
                                            })
                                        );
                                    });
                                } else {
                                    $('#exclude-post-search-results').append(
                                        $('<option>', {
                                            value: '',
                                            text: 'No posts found'
                                        })
                                    );
                                }
                            }
                        });
                    }, 500);
                });

                // Add selected post to exclusion list
                $('#add-exclude-post').on('click', function() {
                    var selectedOption = $('#exclude-post-search-results option:selected');
                    var postId = selectedOption.val();
                    var postTitle = selectedOption.text();

                    if (postId) {
                        // Check if already added
                        if ($('#excluded-posts-list input[value="' + postId + '"]').length === 0) {
                            $('.no-excluded-posts-message').remove();

                            $('#excluded-posts-list').append(
                                $('<li>', {
                                    html: '<input type="hidden" name="wp_tts_options[exclude_posts][]" value="' + postId + '">' +
                                        postTitle +
                                        ' <button type="button" class="remove-exclude-post button-link" data-id="' + postId + '">Remove</button>'
                                })
                            );
                        }
                    }
                });

                // Remove post from exclusion list
                $(document).on('click', '.remove-exclude-post', function() {
                    var listItem = $(this).closest('li');
                    listItem.remove();

                    if ($('#excluded-posts-list li').length === 0) {
                        $('#excluded-posts-list').append(
                            '<li class="no-excluded-posts-message">No posts excluded</li>'
                        );
                    }
                });
            });
        </script>
		<?php
		echo '</div>';
	}

	/**
	 * Render position setting field
	 */
	public function position_callback() {
		$options = get_option('wp_tts_options', array(
			'position' => 'before',
		));

		$positions = array(
			'before' => 'Before content',
			'after' => 'After content',
		);

		foreach ($positions as $value => $label) {
			$checked = ($options['position'] === $value) ? 'checked' : '';
			echo '<label>';
			echo '<input type="radio" name="wp_tts_options[position]" value="' . esc_attr($value) . '" ' . $checked . '> ';
			echo esc_html($label);
			echo '</label><br>';
		}
	}

	/**
	 * Add meta box for individual post/page settings
	 */
	public function add_tts_meta_box() {
		$post_types = get_post_types(array('public' => true));

		foreach ($post_types as $post_type) {
			add_meta_box(
				'wp_tts_meta_box',
				'Text to Speech Settings',
				array($this, 'render_tts_meta_box'),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render TTS meta box
	 */
	public function render_tts_meta_box($post) {
		// Add nonce for security
		wp_nonce_field('wp_tts_meta_box', 'wp_tts_meta_box_nonce');

		// Get saved value
		$value = get_post_meta($post->ID, '_wp_tts_enable', true);

		echo '<p>Override global settings for this specific post?</p>';

		echo '<label>';
		echo '<input type="radio" name="wp_tts_enable" value="" ' . checked($value, '', false) . '> ';
		echo 'Use global settings';
		echo '</label><br>';

		echo '<label>';
		echo '<input type="radio" name="wp_tts_enable" value="yes" ' . checked($value, 'yes', false) . '> ';
		echo 'Always enable TTS';
		echo '</label><br>';

		echo '<label>';
		echo '<input type="radio" name="wp_tts_enable" value="no" ' . checked($value, 'no', false) . '> ';
		echo 'Always disable TTS';
		echo '</label>';
	}

	/**
	 * Save meta box data
	 */
	public function save_tts_meta_box_data($post_id) {
		// Check if nonce is set
		if (!isset($_POST['wp_tts_meta_box_nonce'])) {
			return;
		}

		// Verify nonce
		if (!wp_verify_nonce($_POST['wp_tts_meta_box_nonce'], 'wp_tts_meta_box')) {
			return;
		}

		// If this is an autosave, don't do anything
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// Check permission
		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		// Save the meta value
		if (isset($_POST['wp_tts_enable'])) {
			$value = sanitize_text_field($_POST['wp_tts_enable']);
			update_post_meta($post_id, '_wp_tts_enable', $value);
		}
	}

	/**
	 * Render admin settings page
	 */
	public function render_settings_page() {
		?>
        <div class="wrap">
            <h1>Text to Speech Settings</h1>
            <form method="post" action="options.php">
				<?php
				settings_fields('wp_tts_options');
				do_settings_sections('wp-text-to-speech');
				submit_button();
				?>
            </form>
        </div>
		<?php
	}
}

// Initialize the plugin
add_action('plugins_loaded', function() {
	new WP_Text_To_Speech();
});