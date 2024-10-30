<?php

namespace LPagery\data;

use Exception;
use LPagery\service\preparation\InputParamProvider;
use LPagery\service\substitution\ImageSubstitutionHandler;
use LPagery\service\substitution\Spintax;
use LPagery\service\substitution\SubstitutionHandler;
use LPagery\factories\InputParamProviderFactory;
use LPagery\factories\SubstitutionHandlerFactory;
use LPagery\model\Params;

class LPageryDao
{
    private static $instance;

    private function __construct()
    {
    }

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init_db()
    {

        $LPageryDatabaseMigrator = new LPageryDatabaseMigrator();
        $LPageryDatabaseMigrator->migrate();
    }

    private function lpagery_prepare_in($values)
    {
        return implode(',', array_map(function ($value) {
            global $wpdb;

            // Use the official prepare() function to sanitize the value.
            return sanitize_text_field($wpdb->prepare('%s', $value));
        }, $values));
    }

    public function lpagery_search_posts($term, $types, $mode, $select, $post_id)
    {
        global $wpdb;
        if (!$term) {
            $term = "";
        }
        $term = '%' . $term . '%';
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare_in = self::lpagery_prepare_in($types);

        if ($post_id == null || $select !== "lpagery_parent_path") {
            $post_id = 0;
        }

        if ($select === "lpagery_parent_path") {
            $prepare_in = self::lpagery_prepare_in(array(get_post_type($post_id)));
        }
        if ($mode == "update" && $select === "lpagery_parent_path") {

            $prepare = $wpdb->prepare(
                "SELECT ID, post_title FROM $wpdb->posts p where post_title like %s and post_type in ($prepare_in) and post_status in ('private', 'publish', 'draft') 
                                        and not exists(select pp.id from $table_name_process_post pp where pp.post_id = p.id) and $post_id != p.id",
                $term
            );
        } elseif ($mode == "create" || $select === "lpagery_parent_path") {
            $prepare = $wpdb->prepare(
                "SELECT ID, post_title FROM $wpdb->posts p where post_title like %s and post_type in ($prepare_in) and post_status in ('private', 'publish', 'draft') and $post_id != p.id",
                $term
            );
        } else {
            $prepare = $wpdb->prepare(
                "SELECT p.ID, p.post_title FROM $wpdb->posts p where post_title like %s and post_type in ($prepare_in) and post_status in ('private', 'publish', 'draft') and exists(select pr.id from $table_name_process pr where pr.post_id = p.id ) and $post_id != p.id",
                $term
            );
        }
        return $wpdb->get_results($prepare);
    }

    public function lpagery_search_attachment($term, $ignore_ending = false)
    {
        global $wpdb;
        if (!$ignore_ending) {
            $term_with_wildcard = '%' . $term;
        } else {
            $term_with_wildcard = '%' . $term . '%';
        }

        $prepare = $wpdb->prepare(
            "SELECT ID, guid, pm.meta_value as file_name, post_content, post_excerpt, post_title, post_name, post_content_filtered
                    FROM $wpdb->posts p inner join $wpdb->postmeta pm on p.ID = pm.post_id
                    where pm.meta_key = '_wp_attached_file' and pm.meta_value like %s and p.post_type = 'attachment'
                    order by post_name",
            $term_with_wildcard
        );
        $results = $wpdb->get_results($prepare);

        if (empty($results)) {
            return null;
        }

        return $results;
    }

    public function lpagery_search_attachment_by_id($id)
    {
        global $wpdb;

        $prepare = $wpdb->prepare(
            "SELECT ID,post_title, guid FROM $wpdb->posts p where ID = %s and post_type = 'attachment' ",
            $id
        );

        return $wpdb->get_results($prepare);
    }

    public function lpagery_has_template_pending_changes($id)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        if (self::lpagery_table_exists()) {
            $prepare = $wpdb->prepare(
                "select (count(p.ID) > 0) as post_exists
					from $wpdb->posts p
					         inner join $table_name_process_post lpp on p.ID = lpp.post_id
					        inner join $wpdb->posts  source_post on lpp.template_id = source_post.id
					where lpp.template_id = %s
					  and p.post_status != 'trash' and source_post.post_modified > lpp.modified
					order by p.id",
                $id
            );
            $result = (array)$wpdb->get_results($prepare)[0];

            return boolval($result['post_exists']);
        }
        return false;
    }

    public function lpagery_get_posts_by_template_id($id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare(
            "select p.ID, lpp.lpagery_process_id as process_id, post_title, post_name, (coalesce(lpp.modified < p.post_modified, false)) as modified
					from $wpdb->posts p
			         inner join $table_name_process_post lpp on p.ID = lpp.post_id
			where lpp.template_id = %s
			  and  p.post_status != 'trash'
			order by p.id",
            $id
        );

        return $wpdb->get_results($prepare);

    }

    public function lpagery_upsert_process($post_id, $process_id, $purpose, $data, $google_sheet_data, $google_sheet_sync_enabled)
    {
        global $wpdb;
        $current_user_id = get_current_user_id();
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        if (($process_id) <= 0) {
            $wpdb->insert($table_name_process, array(
                "post_id" => $post_id,
                "user_id" => $current_user_id,
                "purpose" => $purpose,
                "data" => serialize($data),
                "google_sheet_data" => serialize($google_sheet_data),
                "google_sheet_sync_enabled" => $google_sheet_sync_enabled,
                "google_sheet_sync_status" => $google_sheet_sync_enabled ? "PLANNED" : null,
                "created" => current_time('mysql')
            ));
            return $wpdb->insert_id;
        } else {
            if ($data) {
                $existing_process = self::lpagery_get_process_by_id($process_id);
                $old_slug = maybe_unserialize($existing_process->data)["slug"];
                $new_slug = $data["slug"];
                $wpdb->update($table_name_process, array(
                    "data" => serialize($data),
                ), array("id" => $process_id));
                if ($old_slug !== $new_slug) {
                    $process_posts = self::lpagery_get_process_post_input_data($process_id);
                    foreach ($process_posts as $process_post) {
                        $params = InputParamProviderFactory::create()->lpagery_get_input_params_without_images(maybe_unserialize($process_post->data));
                        $slug = SubstitutionHandlerFactory::create()->lpagery_substitute($params, $new_slug);
                        $slug = sanitize_title($slug);
                        $wpdb->update($table_name_process_post, array("replaced_slug" => $slug), array("id" => $process_post->id));
                    }
                }
            }
            if ($google_sheet_data) {
                $wpdb->update($table_name_process, array(
                    "google_sheet_data" => serialize($google_sheet_data),
                    "google_sheet_sync_enabled" => $google_sheet_sync_enabled
                ), array("id" => $process_id));
            }
            if ($purpose) {
                $wpdb->update($table_name_process, array(
                    "purpose" => $purpose
                ), array("id" => $process_id));
            }
        }

        return $process_id;
    }

    public function lpagery_add_post_to_process(Params $params, $post_id, $template_id, $replaced_slug)
    {
        global $wpdb;

        $wpdb->suppress_errors = true;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $table_name_process = $wpdb->prefix . 'lpagery_process';

        $process_id = $params->process_id;
        $sanitized_slug = sanitize_title($replaced_slug);
        $prepare = $wpdb->prepare("select lpp.id from $table_name_process_post lpp inner join $wpdb->posts p on p.id = lpp.post_id where lpp.lpagery_process_id = %s and lpp.replaced_slug = %s and lpp.post_id != %s", $process_id, $sanitized_slug, $post_id);
        $existing_process_post_with_another_id = $wpdb->get_results($prepare);
        if ($existing_process_post_with_another_id) {
            return array("created_id" => null, "error" => "Post with the same slug $sanitized_slug already exists in process $process_id ");
        }

        $process_data = $wpdb->get_results($wpdb->prepare("SELECT data FROM $table_name_process where id = %s", $process_id));

        $spintax_enabled = $params->spintax_enabled;
        $image_processing_enabled =$params->image_processing_enabled;
        $process_config = !empty($process_data) ? $process_data[0]->data : null;
        $lpagery_settings = serialize(array("spintax_enabled" => $spintax_enabled, "image_processing_enabled" => $image_processing_enabled));

        $prepare = $wpdb->prepare("select lpp.id from $table_name_process_post lpp where lpp.lpagery_process_id = %s and lpp.post_id = %s", $process_id, $post_id);
        $process_post_already_exists = $wpdb->get_results($prepare);
        if ($process_post_already_exists) {
            $update_result = $wpdb->update(
                $table_name_process_post,
                array(
                    "data" => serialize($params->raw_data),
                    "replaced_slug" => $sanitized_slug,
                    "config" => $process_config,
                    "lpagery_settings" => $lpagery_settings,
                    "template_id" => $template_id,
                    "modified" => current_time('mysql')
                ),
                array("post_id" => $post_id, "lpagery_process_id" => $process_id)
            );

            if ($update_result === false) {
                throw new Exception("Failed to update post $post_id in process $process_id " . $wpdb->last_error);
            }
        } else {
            $wpdb->insert($table_name_process_post, array(
                "post_id" => $post_id,
                "lpagery_process_id" => $process_id,
                "data" => serialize($params->raw_data),
                "created" => current_time('mysql'),
                "replaced_slug" => $sanitized_slug,
                "config" => $process_config,
                "lpagery_settings" => $lpagery_settings,
                "template_id" => $template_id,
                "modified" => current_time('mysql')
            ));
            if (!$wpdb->insert_id) {
                throw new Exception("Failed to add post $post_id to process $process_id " . $wpdb->last_error);
            }
        }
        $wpdb->suppress_errors = false;

        return array("created_id" => $wpdb->insert_id, "error" => null);
    }


    public function lpagery_get_processes_by_source_post($post_id)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare(
            "select id,
			       user_id,
			       post_id,
			       data,
			       created,
    				purpose,
			       (select count(lpp.id) from $table_name_process_post lpp inner join $wpdb->posts p on p.id = lpp.post_id  where lpp.lpagery_process_id = lp.id and p.post_status != 'trash') as count
			from $table_name_process lp
			where post_id = %s",
            $post_id
        );

        return $wpdb->get_results($prepare);

    }

    public function lpagery_search_processes($post_id, $user_id, $search)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';


        $where_query = array();

        if (is_numeric($post_id) && $post_id >= 0) {
            $where_query[] = "post_id=" . $post_id;
        }
        if (is_numeric($user_id) && $user_id >= 0) {
            $where_query[] = "user_id=" . $user_id;
        }
        if (!empty($search) && $search != 'undefined') {
            $prepared = '%%%' . $search . '%%';

            $where_query[] = sprintf("purpose like '%s'", $prepared);
        }
        if (empty($where_query)) {
            $where_query[] = "true";
        }
        $where_query_text = " WHERE " . implode(' AND ', $where_query) . " ORDER BY created desc";

        $prepare =
            "select id,
			       user_id,
			       google_sheet_sync_enabled,
			       google_sheet_sync_status,
			       last_google_sheet_sync,
			       google_sheet_data,
			       post_id,
			       created,
    				purpose,
			        (select count(lpp.id) from $table_name_process_post lpp inner join $wpdb->posts p on p.id = lpp.post_id where lpp.lpagery_process_id = lp.id and p.post_status != 'trash') as count
			from $table_name_process lp	$where_query_text";

        return $wpdb->get_results($prepare);

    }

    public function lpagery_get_process_by_id($process_id)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare(
            "select id,
       				purpose,
			       user_id,
			       post_id,
			       data,
			       google_sheet_sync_enabled,
			       google_sheet_sync_status,
			       google_sheet_sync_error,
			       last_google_sheet_sync,
			       google_sheet_data,
			       created,
			         (select count(lpp.id) from $table_name_process_post lpp inner join $wpdb->posts p on p.id = lpp.post_id where lpp.lpagery_process_id = lp.id and p.post_status != 'trash') as count
			from $table_name_process lp
			where id = %s",
            $process_id
        );

        $results = $wpdb->get_results($prepare);
        return empty($results) ? null : $results[0];

    }


    public function lpagery_update_process_post_data($process_id, $data, $post_id, $slug, $replaced_slug)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $wpdb->update($table_name_process_post, array(
            "data" => serialize($data),
            "replaced_slug" => $replaced_slug,
            "modified" => current_time('mysql')
        ), array("post_id" => $post_id, "lpagery_process_id" => $process_id));

        return $wpdb->insert_id;
    }

    public function lpagery_update_process_modified($post_id)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $wpdb->update($table_name_process_post, array(
            "modified" => current_time('mysql')
        ), array("post_id" => $post_id));

        return $wpdb->insert_id;
    }

    public function lpagery_get_process_post_input_data($process_id, $download = false)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare(
            "select lpp.id, post_id, data
			from $table_name_process_post lpp
			inner join $wpdb->posts p on p.id = lpp.post_id
			where lpagery_process_id = %s and p.post_status != 'trash' order by lpp.post_id",
            $process_id
        );
        $results = $wpdb->get_results($prepare);
        if ($download) {
            $results = array_map(function ($value) {
                $permalink = get_permalink($value->post_id);
                $value->permalink = $permalink;
                return $value;
            }, $results);

        }
        return $results;
    }

    public function lpagery_get_process_post_data($generated_post_id)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare(
            "select lpp.data as data, lpp.template_id as source_id, lpp.lpagery_process_id as process_id, lpp.modified as modified
			from $table_name_process_post lpp
			inner join $wpdb->posts p on p.id = lpp.post_id
			where lpp.post_id = %s and p.post_status != 'trash' order by lpp.id",
            $generated_post_id
        );

        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return array();
        }
        return $results [0];
    }

    public function lpagery_get_users_with_processes()
    {
        global $wpdb;

        $table_name_process = $wpdb->prefix . 'lpagery_process';
        if (self::lpagery_table_exists()) {
            return $wpdb->get_results("select u.id, u.display_name from $wpdb->users u where exists(select id from $table_name_process lp where lp.user_id  = u.id)");
        }
        return array();

    }

    public function lpagery_get_template_posts()
    {
        global $wpdb;

        $table_name_process = $wpdb->prefix . 'lpagery_process';
        if (self::lpagery_table_exists()) {
            return $wpdb->get_results("SELECT p.id, p.post_title FROM $wpdb->posts p where post_status in ('publish', 'draft','private', 'trash') and exists(select pr.id from $table_name_process pr where pr.post_id = p.id )");
        }
        return array();
    }

    public function lpagery_get_posts_by_process($process_id)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare(
            "select p.id
				from $wpdb->posts p
         inner join $table_name_process_post lpp on p.ID = lpp.post_id where lpp.lpagery_process_id = %s and p.post_status != 'trash'",
            $process_id
        );

        return $wpdb->get_results($prepare);
    }

    public function lpagery_delete_process($process_id)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $wpdb->delete($table_name_process_post, array("lpagery_process_id" => $process_id));
        $wpdb->delete($table_name_process, array("id" => $process_id));
    }

    public function lpagery_delete_process_post($process_id, $post_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $wpdb->delete($table_name_process_post, array("lpagery_process_id" => $process_id, "post_id" => $post_id));
    }

    public function lpagery_find_post_by_name_and_type_equal($search_term, $post_type)
    {
        global $wpdb;
        $search_term = esc_sql($search_term);
        $search_term = strtolower($search_term);
        $prepare = $wpdb->prepare(
            "select p.id as id, p.post_title as post_title
                from $wpdb->posts p where lower(post_name) = %s and post_type = %s and post_status in ('publish', 'draft', 'private')
            order by post_date
            limit 1;",
            $search_term,
            $post_type
        );

        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        }
        return (array)$results[0];
    }

    public function lpagery_find_post_by_id($id)
    {
        $post_type = get_post_type($id);
        global $wpdb;
        $id = intval($id);

        $prepare = $wpdb->prepare(
            "select p.id as id, p.post_title as post_title
              from $wpdb->posts p
              where p.id = %s
                and p.post_type = %s
                and p.post_status in ('private', 'draft', 'publish')",
            $id,
            $post_type
        );

        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        }

        return (array)$results[0];
    }


    public function lpagery_is_post_template_with_created_posts($post_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare(
            "select exists(select lpp.id
              from $table_name_process_post lpp
                       inner join $wpdb->posts p on p.ID = lpp.post_id
              where p.post_status != 'trash'
                and lpp.template_id = %s) as created_page_exists",
            $post_id
        );
        return filter_var($wpdb->get_results($prepare)[0]->created_page_exists, FILTER_VALIDATE_BOOLEAN);
    }

    public function lpagery_get_process_id_by_template($post_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare(
            "select lpagery_process_id as process_id
              from $table_name_process_post
              where template_id = %s limit 1",
            $post_id
        );
        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        }
        return (array)$results[0];
    }

    public function lpagery_get_existing_post_by_slug_in_process(int $process_id,string $slug)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare(
            "select p.ID, post_title, lpagery_process_id as 'process_id', post_name,post_content_filtered,post_excerpt,post_content,post_status,post_parent,post_date, (coalesce(lpp.modified < p.post_modified, false)) as modified
                    from $wpdb->posts p
                             inner join $table_name_process_post lpp on lpp.post_id = p.id
                    where lpp.lpagery_process_id = %s
                    and (lpp.replaced_slug = %s) order by post_name",
            $process_id,
            $slug
        );
        $results = $wpdb->get_results($prepare);
        if (empty($results)) {

            $prepare = $wpdb->prepare(
                "select p.ID, post_title, lpagery_process_id as 'process_id', post_name,post_content_filtered,post_excerpt,post_content,post_status,post_parent,post_date, (coalesce(lpp.modified < p.post_modified, false)) as modified
                    from $wpdb->posts p
                             inner join $table_name_process_post lpp on lpp.post_id = p.id
                    where lpp.lpagery_process_id = %s
                    and (p.post_name = %s) order by post_name",
                $process_id,
                $slug
            );
            $results = $wpdb->get_results($prepare);
        }
        if (empty($results)) {
            return null;
        } else {
            return (array)$results[0];
        }
    }

    public function lpagery_get_processes_with_google_sheet_sync()
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $results = $wpdb->get_results(
            "select id, data, post_id, created, google_sheet_data
            from $table_name_process where google_sheet_sync_enabled"
        );
        return array_map(function ($element) {
            return array(
                "id" => $element->id,
                "created" => $element->created,
                "data" => maybe_unserialize($element->data),
                "google_sheet_data" => maybe_unserialize($element->google_sheet_data),
                "post_id" => $element->post_id);
        }, $results);
    }

    public function lpagery_get_process_posts_slugs($process_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepared = $wpdb->prepare(
            "select id, post_id, replaced_slug
            from $table_name_process_post where lpagery_process_id = %s",
            $process_id
        );
        $result = $wpdb->get_results($prepared);
        return $result;
    }

    public function lpagery_update_process_sync_status($process_id, $status, $error = null)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        if ($status == "ERROR") {
            $wpdb->update(
                $table_name_process,
                array("google_sheet_sync_status" => $status, "google_sheet_sync_error" => $error),
                array("id" => $process_id, "google_sheet_sync_enabled" => true)
            );
        } elseif ($status == "FINISHED") {
            $wpdb->update(
                $table_name_process,
                array("google_sheet_sync_status" => $status, "google_sheet_sync_error" => null, "last_google_sheet_sync" => current_time('mysql', true)),
                array("id" => $process_id, "google_sheet_sync_enabled" => true)
            );
        } else {
            $wpdb->update(
                $table_name_process,
                array("google_sheet_sync_status" => $status),
                array("id" => $process_id, "google_sheet_sync_enabled" => true)
            );
        }
    }

    public function lpagery_get_process_config_changed($process_id, $post_id)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare(
            "SELECT data FROM $table_name_process WHERE id = %s",
            $process_id
        );
        $process_data = $wpdb->get_var($prepare);
        $prepare = $wpdb->prepare(
            "SELECT config FROM $table_name_process_post WHERE post_id = %s and lpagery_process_id = %s",
            $post_id,
            $process_id
        );
        $process_post_data = $wpdb->get_var($prepare);

        return ($process_data) !== ($process_post_data);

    }

    public function lpagery_get_process_post_global_settings($process_id, $post_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare(
            "SELECT lpagery_settings FROM $table_name_process_post WHERE post_id = %s and lpagery_process_id = %s",
            $post_id,
            $process_id
        );
        $lpagery_settings = $wpdb->get_var($prepare);
        return maybe_unserialize($lpagery_settings);

    }

    public function lpagery_get_existing_posts_by_slug($slugs, $process_id, $post_type)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $lpagery_prepare_in = self::lpagery_prepare_in($slugs);
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "select p.id as id, post_name, post_type
                from $wpdb->posts p
                where post_name in ($lpagery_prepare_in)
                  and post_status in ('private', 'publish', 'draft', 'future')
                  and post_type = %s
                   and not exists(select id from $table_name_process_post lpp where lpp.post_id = p.id and lpp.lpagery_process_id = %s)",
                $post_type,
                $process_id
            )
        );
        foreach ($results as $post) {
            $post->permalink = get_permalink($post->id);
        }

        return $results;
    }

    private function lpagery_table_exists()
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare("SELECT EXISTS (
                SELECT
                    TABLE_NAME
                FROM
                    information_schema.TABLES
                WHERE
                        TABLE_NAME = %s
            ) as lpagery_table_exists;", $table_name_process_post);
        $result = (array)$wpdb->get_results($prepare)[0];
        return $result['lpagery_table_exists'];
    }

    public function lpagery_count_processes()
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $prepare = $wpdb->prepare("SELECT count(*) as count FROM $table_name_process");
        $result = (array)$wpdb->get_results($prepare)[0];
        return $result['count'];
    }

    public function lpagery_get_first_process_date()
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $prepare = $wpdb->prepare("SELECT created FROM $table_name_process order by created asc limit 1");
        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        }
        $result = (array)$results[0];
        return $result['created'];
    }

    public function lpagery_get_post_by_slug_for_link($slug)
    {
        global $wpdb;
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "select p.id , post_title, post_type
                from $wpdb->posts p
                where post_name = %s
                  and post_status in ('private', 'publish', 'draft', 'future')",
                $slug
            )
        );
        if (empty($results)) {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "select p.id , post_title, post_type
                from $wpdb->posts p
                where post_name like %s
                  and post_status in ('private', 'publish', 'draft', 'future')",
                    $slug . '%'
                )
            );
        }

        if (empty($results)) {
            return null;
        } else {
            return (array)$results[0];
        }
    }


    public function lpagery_get_post_at_position_in_process($post_id, $position, $circle)
    {

        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare(
            "select p.id, p.post_title
         from $wpdb->posts p
         inner join $table_name_process_post lpp on lpp.post_id = p.id
         where lpp.lpagery_process_id = (
             select lpagery_process_id
             from $table_name_process_post lpp2
             where lpp2.post_id = %s
         ) order by p.id",
            $post_id
        );
        $results = $wpdb->get_results($prepare);
        $results = (array)$results;
        if (empty($results)) {
            return null;
        }
        if ($position === 'FIRST') {
            return $results[0];
        }
        if ($position === 'LAST') {
            return $results[count($results) - 1];
        }
        if ($position === 'NEXT') {
            $position = 1;
        }
        if ($position === 'PREV') {
            $position = -1;
        }

        $index = -1;
        foreach ($results as $key => $result) {
            if ($result->id == $post_id) {
                $index = $key;
                break;
            }
        }

        if ($index != -1) {
            $target_index = $index + $position;
            if ($circle && $target_index > count($results) - 1) {
                return (array)$results[0];
            }
            if ($circle && $target_index < 0) {
                return (array)$results[count($results) - 1];
            }
            if ($target_index >= 0 && $target_index < count($results)) {
                return (array)$results[$target_index];
            }

        }

        return null;
    }

}
