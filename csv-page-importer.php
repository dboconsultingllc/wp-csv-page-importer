<?php
/**
 * Plugin Name: CSV Page Importer
 * Description: Automatically creates WordPress pages from a CSV file and organizes them under parent (e.g. state) and child (e.g. city) categories.
 * Version: 2.0
 * Author: Brandon Baxley (Modified)
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Plugin constants
define('CSV_IMPORTER_VERSION', '2.0');
define('CSV_IMPORTER_PATH', plugin_dir_path(__FILE__));
define('CSV_IMPORTER_URL', plugin_dir_url(__FILE__));

// Enqueue styles and scripts
function csv_importer_enqueue_scripts() {
    if (is_page()) {
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css');
        wp_enqueue_style('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css');
        wp_enqueue_style('business-listing', CSV_IMPORTER_URL . 'assets/css/business-listing.css', array(), CSV_IMPORTER_VERSION);
        
        wp_enqueue_script('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js', array('jquery'), null, true);
    }
}
add_action('wp_enqueue_scripts', 'csv_importer_enqueue_scripts');

// Function to import pages from CSV
function csv_import_pages($csv_file, $parent_category_id = null) {
    if (!file_exists($csv_file)) {
        echo "<p style='color: red;'>CSV file not found.</p>";
        return;
    }

    $handle = fopen($csv_file, 'r');
    if (!$handle) {
        echo "<p style='color: red;'>Failed to open CSV file.</p>";
        return;
    }

    // Get the parent category slug if a category is selected
    $parent_category_slug = '';
    $parent_category_name = '';
    if ($parent_category_id) {
        $parent_category = get_term($parent_category_id, 'category');
        if ($parent_category && !is_wp_error($parent_category)) {
            $parent_category_slug = $parent_category->slug;
            $parent_category_name = $parent_category->name;
        }
    }

    // Store processed states and cities
    $processed_states = array();
    $processed_cities = array();
    $state_pages = array();
    $city_pages = array();

    $header = fgetcsv($handle);
    
    // Find column indexes for all possible fields
    $columns = array(
        'name' => array_search('name', $header),
        'city' => array_search('city', $header),
        'state' => array_search('state', $header),
        'address' => array_search('formatted_address', $header),
        'phone' => array_search('formatted_phone_number', $header),
        'rating' => array_search('rating', $header),
        'website' => array_search('website', $header),
        'email' => array_search('email', $header),
        'description' => array_search('description', $header),
        'latitude' => array_search('latitude', $header),
        'longitude' => array_search('longitude', $header),
        'hours' => array_search('business_hours', $header),
        'photos' => array_search('photos', $header),
        'social_media' => array_search('social_media', $header),
        'amenities' => array_search('amenities', $header),
        'categories' => array_search('categories', $header)
    );

    // Check required columns
    if ($columns['name'] === false || $columns['city'] === false || $columns['state'] === false) {
        echo "<p style='color: red;'>Required columns (name, city, state) not found in CSV.</p>";
        fclose($handle);
        return;
    }

    while (($data = fgetcsv($handle)) !== false) {
        // Get basic information
        $business_name = sanitize_text_field($data[$columns['name']]);
        $city = sanitize_text_field($data[$columns['city']]);
        $state = sanitize_text_field($data[$columns['state']]);
        
        // Get additional fields if they exist
        $metadata = array();
        foreach ($columns as $key => $index) {
            if ($index !== false && isset($data[$index])) {
                $metadata['_business_' . $key] = sanitize_text_field($data[$index]);
            }
        }
        
        $business_slug = sanitize_title($business_name);
        $state_slug = sanitize_title($state);
        $city_slug = sanitize_title($city);

        // Create or get state category
        if (!isset($processed_states[$state])) {
            $state_cat = get_term_by('name', $state, 'category');
            if (!$state_cat) {
                $state_cat = wp_insert_term($state, 'category', array(
                    'description' => "Listings in $state",
                    'parent' => $parent_category_id // Set as child of parent category if one is selected
                ));
                
                if (!is_wp_error($state_cat)) {
                    $state_cat_id = $state_cat['term_id'];
                } else {
                    echo "<p style='color: red;'>Failed to create state category for $state</p>";
                    continue;
                }
            } else {
                $state_cat_id = $state_cat->term_id;
            }
            $processed_states[$state] = $state_cat_id;
            
            // Create state archive page if it doesn't exist
            $state_page = get_page_by_path($state_slug, OBJECT, 'page');
            if (!$state_page) {
                $state_page_id = wp_insert_post([
                    'post_title'   => $state,
                    'post_content' => '', // We'll update this later with list of cities
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_name'    => $state_slug,
                ]);
                
                // Assign the state category to the page
                wp_set_object_terms($state_page_id, $state_cat_id, 'category');
                
                $state_pages[$state] = $state_page_id;
            } else {
                $state_pages[$state] = $state_page->ID;
            }
        }
        
        $state_cat_id = $processed_states[$state];
        $state_page_id = $state_pages[$state];
        
        // Create or get city category as child of state category
        $city_state_key = $city . '-' . $state;
        if (!isset($processed_cities[$city_state_key])) {
            $city_cat = get_term_by('name', $city, 'category');
            if (!$city_cat) {
                $city_cat = wp_insert_term($city, 'category', array(
                    'description' => "Listings in $city, $state",
                    'parent' => $state_cat_id // Set state as parent
                ));
                
                if (!is_wp_error($city_cat)) {
                    $city_cat_id = $city_cat['term_id'];
                } else {
                    echo "<p style='color: red;'>Failed to create city category for $city</p>";
                    continue;
                }
            } else {
                $city_cat_id = $city_cat->term_id;
            }
            $processed_cities[$city_state_key] = $city_cat_id;
            
            // Create city archive page if it doesn't exist
            $city_page = get_page_by_path($state_slug . '/' . $city_slug, OBJECT, 'page');
            if (!$city_page) {
                $city_page_id = wp_insert_post([
                    'post_title'   => $city,
                    'post_content' => '', // We'll update this later with business listings
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_name'    => $city_slug,
                    'post_parent'  => $state_page_id, // Set state page as parent
                    'page_template' => 'city-template.php',
                ]);
                
                // Assign the city category to the page
                wp_set_object_terms($city_page_id, $city_cat_id, 'category');
                
                $city_pages[$city_state_key] = $city_page_id;
            } else {
                $city_pages[$city_state_key] = $city_page->ID;
            }
            
            // Add city to state page content as H2 heading
            $state_content = get_post_field('post_content', $state_page_id);
            $city_permalink = get_permalink($city_pages[$city_state_key]);
            $state_content .= "<h2><a href='" . $city_permalink . "'>$city</a></h2>\n";
            
            wp_update_post([
                'ID'           => $state_page_id,
                'post_content' => $state_content,
            ]);
        }
        
        $city_cat_id = $processed_cities[$city_state_key];
        $city_page_id = $city_pages[$city_state_key];

        // Create business listing page with enhanced content
        $listing_path = $state_slug . '/' . $city_slug . '/' . $business_slug;
        $listing_page = get_page_by_path($listing_path, OBJECT, 'page');
        
        if (!$listing_page) {
            // Create content for the business listing
            $listing_content = '';
            if (isset($metadata['_business_description'])) {
                $listing_content .= $metadata['_business_description'];
            }
            
            $listing_page_id = wp_insert_post([
                'post_title' => $business_name,
                'post_content' => $listing_content,
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_name' => $business_slug,
                'post_parent' => $city_page_id,
                'page_template' => 'templates/listing-template.php'
            ]);
            
            // Save all metadata
            foreach ($metadata as $meta_key => $meta_value) {
                update_post_meta($listing_page_id, $meta_key, $meta_value);
            }
            
            // Handle business hours if provided
            if (isset($metadata['_business_hours'])) {
                $hours = json_decode($metadata['_business_hours'], true);
                if (is_array($hours)) {
                    foreach ($hours as $day => $time) {
                        update_post_meta($listing_page_id, '_business_hours_' . strtolower($day), $time);
                    }
                }
            }
            
            // Handle photos if provided
            if (isset($metadata['_business_photos'])) {
                $photos = explode(',', $metadata['_business_photos']);
                $gallery_ids = array();
                
                foreach ($photos as $photo_url) {
                    $image_id = csv_importer_upload_image($photo_url, $listing_page_id);
                    if ($image_id) {
                        $gallery_ids[] = $image_id;
                    }
                }
                
                if (!empty($gallery_ids)) {
                    update_post_meta($listing_page_id, '_business_gallery', implode(',', $gallery_ids));
                    set_post_thumbnail($listing_page_id, $gallery_ids[0]); // Set first image as featured
                }
            }
            
            // Only assign the city category to the listing
            wp_set_object_terms($listing_page_id, array($city_cat_id), 'category');
            
            // Add business to city page content
            $city_content = get_post_field('post_content', $city_page_id);
            $listing_permalink = get_permalink($listing_page_id);
            $rating_stars = isset($metadata['_business_rating']) ? str_repeat('★', intval($metadata['_business_rating'])) : '';
            
            $city_content .= sprintf(
                '<div class="business-card">
                    <h2><a href="%s">%s</a></h2>
                    <div class="rating">%s</div>
                    <div class="address">%s</div>
                </div>',
                esc_url($listing_permalink),
                esc_html($business_name),
                esc_html($rating_stars),
                isset($metadata['_business_address']) ? esc_html($metadata['_business_address']) : ''
            );
            
            wp_update_post([
                'ID' => $city_page_id,
                'post_content' => $city_content
            ]);
        }
    }
    
    fclose($handle);
    echo "<p style='color: green;'>CSV Import Complete! Created pages organized by state and city.</p>";
}

// Helper function to upload images from URL
function csv_importer_upload_image($image_url, $post_id) {
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    $tmp = download_url($image_url);
    if (is_wp_error($tmp)) {
        return false;
    }
    
    $file_array = array(
        'name' => basename($image_url),
        'tmp_name' => $tmp
    );
    
    $id = media_handle_sideload($file_array, $post_id);
    if (is_wp_error($id)) {
        @unlink($file_array['tmp_name']);
        return false;
    }
    
    return $id;
}

// Admin menu for uploading and importing CSV
function csv_page_importer_menu() {
    add_menu_page(
        'CSV Page Importer',
        'CSV Importer',
        'manage_options',
        'csv-page-importer',
        'csv_importer_admin_page'
    );
}
add_action('admin_menu', 'csv_page_importer_menu');

// Admin page for uploading CSV
function csv_importer_admin_page() {
    ?>
    <div class="wrap">
        <h1>CSV Page Importer</h1>
        <p>Upload a CSV file to automatically create pages organized by state and city.</p>
        <p>Required CSV columns: name, city, state</p>
        <p>Optional columns: formatted_address, formatted_phone_number, rating, website</p>
        
        <form method="post" enctype="multipart/form-data">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="csv_file">CSV File</label></th>
                    <td><input type="file" name="csv_file" id="csv_file" accept=".csv" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="parent_category">Parent Category (Optional)</label></th>
                    <td>
                        <select name="parent_category_id" id="parent_category">
                            <option value="">-- No Parent Category --</option>
                            <?php
                            $categories = get_categories(array('hide_empty' => 0));
                            foreach ($categories as $category) {
                                echo '<option value="' . $category->term_id . '">' . $category->name . '</option>';
                            }
                            ?>
                        </select>
                        <p class="description">State categories will be created as children of this category.</p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="upload_csv" class="button-primary" value="Upload and Import">
            </p>
        </form>
    </div>
    <?php

    if (isset($_POST['upload_csv'])) {
        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $uploaded_file = $_FILES['csv_file']['tmp_name'];
            $destination = WP_CONTENT_DIR . '/uploads/' . $_FILES['csv_file']['name'];
            $parent_category_id = !empty($_POST['parent_category_id']) ? intval($_POST['parent_category_id']) : null;

            if (move_uploaded_file($uploaded_file, $destination)) {
                csv_import_pages($destination, $parent_category_id);
            } else {
                echo "<p style='color: red;'>Failed to upload file.</p>";
            }
        }
    }
}

// Modify permalinks to remove author, date, etc. (requirement #1)
function modify_permalinks_structure() {
    global $wp_rewrite;
    
    // Set permalink structure to just post name
    $wp_rewrite->set_permalink_structure('/%postname%/');
    
    // Flush rewrite rules to apply changes
    $wp_rewrite->flush_rules();
}
register_activation_hook(__FILE__, 'modify_permalinks_structure');

// Add a simple function to flush rewrite rules on plugin activation
function csv_importer_activate() {
    // Create templates directory if it doesn't exist
    $template_dir = get_template_directory() . '/templates/';
    if (!file_exists($template_dir)) {
        mkdir($template_dir, 0755, true);
    }
    
    // Copy template files
    copy(CSV_IMPORTER_PATH . 'templates/listing-template.php', $template_dir . 'listing-template.php');
    
    // Create assets directory and copy CSS
    $assets_dir = CSV_IMPORTER_PATH . 'assets/css/';
    if (!file_exists($assets_dir)) {
        mkdir($assets_dir, 0755, true);
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'csv_importer_activate');

// Add meta box for services
function add_services_meta_box() {
    add_meta_box(
        'services_meta_box', // ID
        'Business Services', // Title
        'render_services_meta_box', // Callback function
        'page', // Post type
        'normal', // Context
        'high' // Priority
    );
}
add_action('add_meta_boxes', 'add_services_meta_box');

// Render services meta box content
function render_services_meta_box($post) {
    // Add nonce for security
    wp_nonce_field('services_meta_box_nonce', 'services_meta_box_nonce');

    // Get existing services
    $services = get_post_meta($post->ID, '_business_services', true);
    $services_array = $services ? explode(',', $services) : array('');

    echo '<div id="services_container">';
    echo '<p><strong>Add or remove services offered by this business:</strong></p>';
    
    foreach ($services_array as $index => $service) {
        echo '<div class="service-input-group" style="margin-bottom: 10px;">';
        echo '<input type="text" name="business_services[]" value="' . esc_attr(trim($service)) . '" style="width: 80%;" />';
        echo ' <button type="button" class="button remove-service" style="' . ($index === 0 ? 'display:none;' : '') . '">Remove</button>';
        echo '</div>';
    }
    
    echo '</div>';
    echo '<button type="button" class="button add-service">Add Another Service</button>';

    // Add JavaScript for dynamic service fields
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Add new service field
        $('.add-service').click(function() {
            var newField = $('.service-input-group:first').clone();
            newField.find('input').val('');
            newField.find('.remove-service').show();
            $('#services_container').append(newField);
        });

        // Remove service field
        $('#services_container').on('click', '.remove-service', function() {
            $(this).parent('.service-input-group').remove();
        });
    });
    </script>
    <?php
}

// Save services meta box data
function save_services_meta_box($post_id) {
    // Check if nonce is set
    if (!isset($_POST['services_meta_box_nonce'])) {
        return;
    }

    // Verify nonce
    if (!wp_verify_nonce($_POST['services_meta_box_nonce'], 'services_meta_box_nonce')) {
        return;
    }

    // If this is an autosave, don't do anything
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Check user permissions
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Save services
    if (isset($_POST['business_services'])) {
        $services = array_filter($_POST['business_services'], 'trim'); // Remove empty values
        $services_string = implode(',', array_map('sanitize_text_field', $services));
        update_post_meta($post_id, '_business_services', $services_string);
    }
}
add_action('save_post', 'save_services_meta_box');