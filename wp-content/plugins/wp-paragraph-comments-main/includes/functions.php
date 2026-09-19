<?php
/**
 * 获取当前的评论者信息
 */
function wp_paragraph_comments_get_current_commenter() {
    if(is_user_logged_in()) {
        $user = wp_get_current_user();
        return array(
            'author' => $user->display_name,
            'author_email' => $user->user_email,
            'author_url' => $user->user_url,
        );
    } else {
        $commenter = wp_get_current_commenter();
        return array(
            'author' => $commenter['comment_author'],
            'author_email' => $commenter['comment_author_email'],
            'author_url' => $commenter['comment_author_url'],
        );
    }
}