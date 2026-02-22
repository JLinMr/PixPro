<?php
/**
 * @param int $current_page 当前页码
 * @param int $total_pages 总页数
 * @param bool $show_edges 是否显示首页和末页（默认true）
 * @return string HTML字符串
 */
function renderPagination($current_page, $total_pages, $show_edges = true) {
    if ($total_pages <= 1) return '';
    
    $html = '';
    
    // 上一页
    if ($current_page > 1) {
        $html .= sprintf('<a class="page-link prev-page" data-page="%d" href="">&laquo;</a>', $current_page - 1);
    }
    
    if ($show_edges) {
        // 首页
        if ($current_page > 2) {
            $html .= '<a class="page-link" data-page="1" href="">1</a>';
            if ($current_page > 3) {
                $html .= '<span class="ellipsis page-link">...</span>';
            }
        }
        
        // 前一页
        if ($current_page > 1) {
            $html .= sprintf('<a class="page-link" data-page="%d" href="">%d</a>', $current_page - 1, $current_page - 1);
        }
    }
    
    // 当前页
    $html .= sprintf('<a class="page-link active" data-page="%d" href="">%d</a>', $current_page, $current_page);
    
    if ($show_edges) {
        // 后一页
        if ($current_page < $total_pages) {
            $html .= sprintf('<a class="page-link" data-page="%d" href="">%d</a>', $current_page + 1, $current_page + 1);
        }
        
        // 末页
        if ($current_page < $total_pages - 1) {
            if ($current_page < $total_pages - 2) {
                $html .= '<span class="ellipsis page-link">...</span>';
            }
            $html .= sprintf('<a class="page-link" data-page="%d" href="">%d</a>', $total_pages, $total_pages);
        }
    }
    
    // 下一页
    if ($current_page < $total_pages) {
        $html .= sprintf('<a class="page-link next-page" data-page="%d" href="">&raquo;</a>', $current_page + 1);
    }
    
    return $html;
}
?>