<?php
/**
 * 段评相关的功能函数
 */

class WP_Paragraph_Comment {
    // 数据库字段
    private $id;
    private $post_id;
    private $paragraph_index;
    private $content;
    private $author;
    private $author_email;
    private $author_url;
    
    // 评论类型标识
    const COMMENT_TYPE = 'wp_paragraph_comment';

    /**
     * 创建新的段评
     *
     * @param array $args 段评参数
     * @return WP_Paragraph_Comment 当前实例
     */
    public function create($args) {
        /* 预处理 */
        $this->post_id = isset($args['post_id']) ? intval($args['post_id']) : null;
        $this->paragraph_index = isset($args['paragraph_index']) ? intval($args['paragraph_index']) : null;
        $this->content = isset($args['content']) ? wp_kses_post(trim($args['content'])) : null;
        $this->author = isset($args['author']) ? sanitize_text_field($args['author']) : null;
        $this->author_email = isset($args['author_email']) ? sanitize_email($args['author_email']) : null;
        $this->author_url = isset($args['author_url']) ? esc_url_raw($args['author_url']) : '';

        /* 判空 */
        if(empty($this->post_id) || empty($this->paragraph_index) || empty($this->content) || empty($this->author) || empty($this->author_email)) {
            error_log('[WP_Paragraph_Comment] Required values are missing.');
            return null;
        }

        /* 校验数据 */
        if(!get_post($this->post_id)) {
            error_log('[WP_Paragraph_Comment] Post ID does not exist: ' . $this->post_id);
            return null;
        }
        if($this->paragraph_index < 0) {
            error_log('[WP_Paragraph_Comment] Invalid paragraph index: ' . $this->paragraph_index);
            return null;
        }
        if(!is_email($this->author_email)) {
            error_log('[WP_Paragraph_Comment] Invalid email: ' . $this->author_email);
            return null;
        }
        if(!empty($this->author_url) && !filter_var($this->author_url, FILTER_VALIDATE_URL)) {
            error_log('[WP_Paragraph_Comment] Invalid URL: ' . $this->author_url);
            return null;
        }

        // 插入评论
        $id = wp_insert_comment(array(
            'comment_post_ID' => $this->post_id,
            'comment_content' => $this->content,
            'comment_author' => $this->author,
            'comment_author_email' => $this->author_email,
            'comment_author_url' => $this->author_url,
            'comment_type' => self::COMMENT_TYPE,
        ));
        $this->id = $id;

        // 将段落索引存储为评论元数据
        add_comment_meta($this->id, 'paragraph_index', $this->paragraph_index, true);

        return $this;
    }

    /**
     * 根据评论 ID 获取段评实例
     *
     * @param int $id 评论 ID
     * @return WP_Paragraph_Comment 当前实例
     */
    public function id($id) {
        $comment = get_comment($id);
        if($comment && $comment->comment_type === self::COMMENT_TYPE) {
            $this->id = $comment->comment_ID;
            $this->post_id = $comment->comment_post_ID;
            $this->paragraph_index = get_comment_meta($this->id, 'paragraph_index', true);
            $this->content = $comment->comment_content;
            $this->author = $comment->comment_author;
            $this->author_email = $comment->comment_author_email;
            $this->author_url = $comment->comment_author_url;
        }

        return $this;
    }

    /**
     * 根据文章 ID 和段落索引获取所有段评
     * 
     * @param int $post_id 文章 ID
     * @param int $paragraph_index 段落索引
     * @param int $page 页码
     * @param int $num 每页数量
     * @return array WP_Comment 实例数组
     */
    public function get_all($post_id, $paragraph_index, $page = 1, $num = 20) {
        $comments = get_comments(array(
            'post_id' => $post_id,
            'type' => self::COMMENT_TYPE,
            'status' => 'approve',
            'orderby' => 'comment_date',
            'order' => 'DESC',
            'number' => $num,
            'offset' => ($page - 1) * $num,
            'meta_query' => array(
                array(
                    'key' => 'paragraph_index',
                    'value' => $paragraph_index,
                    'compare' => '='
                )
            )
        ));

        return $comments;
    }


    /**
     * 获取文章下各个段落的段评数量
     * 
     * @param int $post_id 文章 ID
     * @return array int 计数数组
     */
    public static function get_count_list($post_id) {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                m.meta_value AS paragraph_index, 
                COUNT(c.comment_ID) AS comment_count
            FROM $wpdb->comments c
            INNER JOIN $wpdb->commentmeta m ON c.comment_ID = m.comment_id
            WHERE 
                c.comment_type = %s 
                AND c.comment_approved = '1'
                AND c.comment_post_ID = %d
                AND m.meta_key = 'paragraph_index'
            GROUP BY m.meta_value
            ORDER BY m.meta_value ASC",
            self::COMMENT_TYPE,
            $post_id
        ));

        return $results;
    }

    /* get 方法 */

    public function get_id() {
        return $this->id;
    }

    public function get_post_id() {
        return $this->post_id;
    }

    public function get_paragraph_index() {
        return $this->paragraph_index;
    }

    public function get_content() {
        return $this->content;
    }

    public function get_author() {
        return $this->author;
    }

    public function get_author_email() {
        return $this->author_email;
    }

    public function get_author_url() {
        return $this->author_url;
    }
}