(function($) {
    'use strict';
    
    var postId = null;
    var comment_counts = [];
    var ajax_url = '';
    var nonce = '';
    // 当前打开的段落索引（模块级兜底，避免依赖 DOM 属性时出现 undefined）
    var current_paragraph_index = null;
    // 事件只绑定一次，避免 wpParagraphCommentsInit 被多次调用时事件重复叠加
    var events_bound = false;

    // 读取配置参数，PJAX 换页后需重新调用。
    // 优先读取文章内容里内嵌的 JSON 数据块（#wp-paragraph-comments-data）：
    // - 该数据跟随 PJAX 替换的内容一起更新
    // - 不受主题脚本延迟/优化加载导致的 wp_localize_script 全局变量丢失影响
    function read_config() {
        var cfg = null;
        var $data = $('#wp-paragraph-comments-data');
        if($data.length) {
            try {
                cfg = JSON.parse($data.text());
            } catch(e) {
                cfg = null;
            }
        }
        if(!cfg) {
            // 兜底：旧版 wp_localize_script 输出的全局变量
            cfg = window.wp_paragraph_comments || {};
        }
        postId = cfg.post_id;
        comment_counts = cfg.comment_counts || [];
        ajax_url = cfg.ajax_url;
        nonce = cfg.nonce;
        // 最后兜底：从段落入口的 data-post-id 读取
        if(!postId) {
            var $entry = $('.wp-paragraph-comments-entry').first();
            if($entry.length) {
                postId = $entry.attr('data-post-id');
            }
        }
        // 换页后重置当前打开的段落索引
        current_paragraph_index = null;
    }

    // 为每个段落重新赋值评论数量（PJAX 替换 DOM 后需重新执行）
    function render_comment_counts() {
        comment_counts.forEach(function(data) {
            $('.wp-paragraph-comments-count[data-paragraph-index="' + data.paragraph_index + '"]').text(data.comment_count);
        });
    }

    // 绑定事件委托（只执行一次；事件挂在 document 上，PJAX 替换 DOM 后依然有效）
    function bind_events() {
        if (events_bound) {
            return;
        }
        events_bound = true;

        // 点击打开侧边评论列表
        $(document).on('click', '.wp-paragraph-comments-entry', function() {
            // 优先取 data-paragraph-index，兼容旧版 data-paragraph-id
            var paragraphIndex = $(this).attr('data-paragraph-index') || $(this).attr('data-paragraph-id');
            if(paragraphIndex === undefined) {
                return;
            }
            current_paragraph_index = paragraphIndex;
            create_paragraph_comments_sidebar(paragraphIndex);
        });

        // 点击遮罩层或关闭按钮时隐藏侧边栏
        $(document).on('click', '#wp-paragraph-comments-mask, #wp-paragraph-comments-sidebar-close', function() {
            $('#wp-paragraph-comments-sidebar').removeClass('wp-paragraph-comments-open');
            $('#wp-paragraph-comments-mask').remove();
        });

        // 邮箱填写后通过 AJAX 获取 Gravatar 头像
        $(document).on('blur', '#wp-paragraph-comments-form-email', function() {
            var email = $(this).val().trim();
            if(!is_valid_email(email)) {
                return;
            }
            var avatarImg = $('#wp-paragraph-comments-form-avatar-img');
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'wp_paragraph_comments_get_avatar',
                    nonce: nonce,
                    email: email
                },
                success: function(response) {
                    if(response.success && response.data.avatar) {
                        avatarImg.attr('src', response.data.avatar);
                    }
                }
            });
        });

        // 评论列表滚动到底部时懒加载下一页
        $(document).on('scroll', '#wp-paragraph-comments-list', function() {
            var $list = $(this);
            if($list.attr('data-loading') === 'true' || $list.attr('data-has-more') === 'false') {
                return;
            }
            if($list.scrollTop() + $list.innerHeight() >= this.scrollHeight - 60) {
                var page = parseInt($list.attr('data-page'), 10) + 1;
                $list.attr('data-page', page);
                load_paragraph_comments($list.attr('data-paragraph-index') || current_paragraph_index, page);
            }
        });

        // 点击发送按钮提交评论
        $(document).on('click', '#wp-paragraph-comments-form-submit', function() {
            var $name = $('#wp-paragraph-comments-form-name');
            var $email = $('#wp-paragraph-comments-form-email');
            var $url = $('#wp-paragraph-comments-form-url');
            var $content = $('#wp-paragraph-comments-form-content');
            var paragraphIndex = $('#wp-paragraph-comments-list').attr('data-paragraph-index') || current_paragraph_index;

            var author = $name.val().trim();
            var email = $email.val().trim();
            var url = $url.val().trim();
            var content = $content.val().trim();

            if(!author || !email || !content) {
                alert('请填写昵称、邮箱和评论内容');
                return;
            }
            if(!is_valid_email(email)) {
                alert('邮箱格式不正确');
                return;
            }
            if(url && !/^https?:\/\//i.test(url)) {
                alert('网址格式不正确（需以 http:// 或 https:// 开头）');
                return;
            }

            var $submit = $(this).prop('disabled', true).text('发送中...');

            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'wp_paragraph_comments_create',
                    nonce: nonce,
                    post_id: postId,
                    paragraph_index: paragraphIndex,
                    author: author,
                    author_email: email,
                    author_url: url,
                    content: content
                },
                success: function(response) {
                    if(response.success) {
                        // 清空内容
                        $content.val('');
                        // 直接用新评论数据渲染，插入到列表最前面
                        var $list = $('#wp-paragraph-comments-list');
                        $list.find('.wp-paragraph-comments-empty, .wp-paragraph-comments-end').remove();
                        $list.prepend(render_comment_item(response.data.comment));
                        // 将段落边上的数字加一
                        $('.wp-paragraph-comments-count[data-paragraph-index="' + paragraphIndex + '"]').text(parseInt($('.wp-paragraph-comments-count[data-paragraph-index="' + paragraphIndex + '"]').text()) + 1);
                        // 发送成功，显示绿色提示
                        show_form_message('评论发布成功', 'success');
                    } else {
                        show_form_message(response.data && response.data.message ? response.data.message : '发送失败', 'error');
                    }
                },
                error: function() {
                    show_form_message('网络错误，请重试', 'error');
                },
                complete: function() {
                    $submit.prop('disabled', false).text('发送');
                }
            });
        });
    }

    // 公开的初始化函数：PJAX 导航后手动调用。
    // 用于重新读取 wp_paragraph_comments 配置、刷新各段落的评论数，
    // 并清理上一页可能残留的侧边栏，避免 PJAX 不重新执行脚本导致功能失效。
    window.wpParagraphCommentsInit = function() {
        read_config();
        // 换页后移除上一页可能残留的侧边栏与遮罩
        $('#wp-paragraph-comments-sidebar').remove();
        $('#wp-paragraph-comments-mask').remove();
        render_comment_counts();
    };

    // 首次加载自动初始化
    read_config();
    bind_events();
    $(document).ready(render_comment_counts);

    // 简单的邮箱格式校验
    function is_valid_email(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    // 每页评论数量（与后端 get_all 的 num 保持一致）
    var page_size = 20;

    // 将 WP_Comment 对象渲染为列表项
    function render_comment_item(c) {
        // 用户填了网址则名字变为可点击的超链接
        var author_html = c.author_url
            ? '<a class="wp-paragraph-comments-name" href="' + c.author_url + '" target="_blank" rel="nofollow noopener">' + c.author + '</a>'
            : '<span class="wp-paragraph-comments-name">' + c.author + '</span>';

        return `
            <li class="wp-paragraph-comments-item">
                <img class="wp-paragraph-comments-avatar" src="${c.avatar}" alt="${c.author}">
                <div class="wp-paragraph-comments-body">
                    <div class="wp-paragraph-comments-meta">
                        ${author_html}
                        <span class="wp-paragraph-comments-time">${format_comment_time(c.comment_date)}</span>
                    </div>
                    <p class="wp-paragraph-comments-content">${c.comment_content}</p>
                </div>
            </li>
        `;
    }

    // 把 "2026-08-01 10:24:00" 精简为 "2026-08-01 10:24"
    function format_comment_time(dateStr) {
        return dateStr ? dateStr.substring(0, 16) : '';
    }

    // 在发送按钮左侧显示提示信息（success 绿色 / error 红色，成功后自动消失）
    function show_form_message(message, type) {
        var $msg = $('#wp-paragraph-comments-form-message');
        clearTimeout(show_form_message._timer);
        $msg.text(message)
            .removeClass('wp-paragraph-comments-success wp-paragraph-comments-error')
            .addClass(type === 'success' ? 'wp-paragraph-comments-success' : 'wp-paragraph-comments-error')
            .stop(true, true).fadeIn(200);
        if(type === 'success') {
            show_form_message._timer = setTimeout(function() {
                $msg.fadeOut(300);
            }, 3000);
        }
    }

    // 通过 AJAX 加载指定段落的评论
    function load_paragraph_comments(paragraphIndex, page) {
        var $list = $('#wp-paragraph-comments-list');
        $list.attr('data-loading', 'true');
        $list.append('<li class="wp-paragraph-comments-loading">加载中...</li>');

        $.ajax({
            url: ajax_url,
            type: 'POST',
            data: {
                action: 'wp_paragraph_comments_get_list',
                nonce: nonce,
                post_id: postId,
                paragraph_index: paragraphIndex,
                page: page
            },
            success: function(response) {
                $list.find('.wp-paragraph-comments-loading').remove();
                $list.attr('data-loading', 'false');

                if(!response.success) {
                    if(page === 1) {
                        $list.append('<li class="wp-paragraph-comments-empty">评论加载失败</li>');
                    }
                    return;
                }

                var comments = response.data.comments || [];

                if(!comments.length) {
                    $list.attr('data-has-more', 'false');
                    if(page === 1) {
                        $list.append('<li class="wp-paragraph-comments-empty">还没有评论，快来抢沙发～</li>');
                    } else {
                        $list.append('<li class="wp-paragraph-comments-end">没有更多评论了</li>');
                    }
                    return;
                }

                $list.append(comments.map(render_comment_item).join(''));
                $list.attr('data-has-more', comments.length >= page_size ? 'true' : 'false');
            },
            error: function() {
                // 请求失败时也要移除"加载中"提示，避免一直卡住
                $list.find('.wp-paragraph-comments-loading').remove();
                $list.attr('data-loading', 'false');
                if(page === 1) {
                    $list.append('<li class="wp-paragraph-comments-empty">评论加载失败，请检查后端返回</li>');
                }
            }
        });
    }

    // 切换段落时重置评论列表
    function reset_paragraph_comments_list(paragraphIndex) {
        var $list = $('#wp-paragraph-comments-list');
        $list.empty().attr({
            'data-paragraph-index': paragraphIndex,
            'data-page': 1,
            'data-has-more': 'true'
        });
        load_paragraph_comments(paragraphIndex, 1);
    }

    // 创建侧边评论列表
    function create_paragraph_comments_sidebar(paragraphIndex) {
        current_paragraph_index = paragraphIndex;
        if($('#wp-paragraph-comments-sidebar').length) {
            // 如果侧边栏已经存在，则直接展开（同时补上被移除的遮罩层）
            if(!$('#wp-paragraph-comments-mask').length) {
                $('body').append('<div id="wp-paragraph-comments-mask"></div>');
            }
            // 切换到了其他段落时，重置列表并重新加载
            if($('#wp-paragraph-comments-list').attr('data-paragraph-index') != paragraphIndex) {
                reset_paragraph_comments_list(paragraphIndex);
            }
            $('#wp-paragraph-comments-sidebar').addClass('wp-paragraph-comments-open');
            return;
        }

        var sidebar_html = `
            <aside id="wp-paragraph-comments-sidebar" class="wp-paragraph-comments-open">
                <div class="wp-paragraph-comments-header">
                    <h3>段落评论</h3>
                    <button type="button" id="wp-paragraph-comments-sidebar-close" aria-label="关闭">&times;</button>
                </div>

                <div id="wp-paragraph-comments-form-container"></div>

                <ul class="wp-paragraph-comments-list" id="wp-paragraph-comments-list" data-paragraph-index="${paragraphIndex}" data-page="1" data-has-more="true"></ul>
            </aside>
        `;

        $('body').append('<div id="wp-paragraph-comments-mask"></div>');
        $('body').append(sidebar_html);

        // 通过 AJAX 获取后端生成的评论表单
        $.ajax({
            url: ajax_url,
            type: 'POST',
            data: {
                action: 'wp_paragraph_comments_get_form',
                nonce: nonce
            },
            success: function(response) {
                if(response.success && response.data.html) {
                    $('#wp-paragraph-comments-form-container').html(response.data.html);
                }
            }
        });

        // 打开侧边栏后加载第一页评论
        load_paragraph_comments(paragraphIndex, 1);
    }

})(jQuery);