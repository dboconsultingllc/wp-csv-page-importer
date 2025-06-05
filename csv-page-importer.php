<?php
/**
 * Plugin Name: CSV Page Importer
 * Description: Automatically creates WordPress pages from a CSV file and organizes them under parent (e.g. state) and child (e.g. city) categories.
 * Version: 1.4
 * Author: Brandon Baxley (Modified)
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

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

    // Store processed states and cities to prevent duplicate processing
    $processed_states = array();
    $processed_cities = array();
    $state_pages = array(); // Track state pages
    $city_pages = array(); // Track city pages

    $header = fgetcsv($handle); // Read header row
    
    // Find column indexes
    $name_idx = array_search('name', $header);
    $city_idx = array_search('city', $header);
    $state_idx = array_search('state', $header);
    $address_idx = array_search('formatted_address', $header);
    $phone_idx = array_search('formatted_phone_number', $header);
    $rating_idx = array_search('rating', $header);
    $website_idx = array_search('website', $header);

    // Check if all required columns exist
    if ($name_idx === false || $city_idx === false || $state_idx === false) {
        echo "<p style='color: red;'>Required columns (name, city, state) not found in CSV.</p>";
        fclose($handle);
        return;
    }

    while (($data = fgetcsv($handle)) !== false) {
        $business_name = sanitize_text_field($data[$name_idx]);
        $city = sanitize_text_field($data[$city_idx]);
        $state = sanitize_text_field($data[$state_idx]);
        $address = isset($data[$address_idx]) ? sanitize_text_field($data[$address_idx]) : '';
        $phone = isset($data[$phone_idx]) ? sanitize_text_field($data[$phone_idx]) : '';
        $rating = isset($data[$rating_idx]) ? sanitize_text_field($data[$rating_idx]) : '';
        $website = isset($data[$website_idx]) ? sanitize_text_field($data[$website_idx]) : '';
        
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

        // Create business listing page
        $listing_path = $state_slug . '/' . $city_slug . '/' . $business_slug;
        $listing_page = get_page_by_path($listing_path, OBJECT, 'page');
        
        if (!$listing_page) {
            // Create content for the business listing
            $listing_content = '';
            $listing_content .= "<h1>$business_name</h1>\n";
            $listing_content .= "<p><strong>Address:</strong> $address</p>\n";
            $listing_content .= "<p><strong>Phone:</strong> $phone</p>\n";
            $listing_content .= "<p><strong>Rating:</strong> $rating</p>\n";
            
            if (!empty($website)) {
                $listing_content .= "<p><strong>Website:</strong> <a href='$website' target='_blank'>Visit Website</a></p>\n";
            } else {
                $listing_content .= "<p><strong>Website:</strong> <a href='#'>Website</a></p>\n";
            }
            
            $listing_page_id = wp_insert_post([
                'post_title'   => $business_name,
                'post_content' => $listing_content,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => $business_slug,
                'post_parent'  => $city_page_id, // Set city page as parent
                'page_template' => 'listing-template.php',
            ]);
            
            // Only assign the city category to the listing (modification for requirement #2)
            wp_set_object_terms($listing_page_id, array($city_cat_id), 'category');
            
            // Add only business name/heading to city page content (modification for requirement #3)
            $city_content = get_post_field('post_content', $city_page_id);
            $listing_permalink = get_permalink($listing_page_id);
            
            $city_content .= "<h2><a href='" . $listing_permalink . "'>$business_name</a></h2>\n";
            
            wp_update_post([
                'ID'           => $city_page_id,
                'post_content' => $city_content,
            ]);
        }
    }
    
    fclose($handle);
    echo "<p style='color: green;'>CSV Import Complete! Created pages organized by state and city.</p>";
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
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'csv_importer_activate');