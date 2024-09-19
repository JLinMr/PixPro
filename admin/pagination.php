<?php
function renderPagination($current_page, $total_pages) {
    // 如果总页码大于1，则显示分页链接
    if ($total_pages > 1) {
        $pagination = '<div class="pagination">';

        // 如果当前页码大于1，则显示上一页链接
        if ($current_page > 1) {
            $pagination .= '<a class="page-link prev-page" data-page="' . ($current_page - 1) . '" href="">&laquo;</a> ';
        }

        // 如果当前页码小于等于4，显示1-4页的链接
        if ($current_page <= 4) {
            for ($i = 1; $i <= min(4, $total_pages); $i++) {
                $pagination .= '<a class="page-link' . ($i == $current_page ? ' active' : '') . '" data-page="' . $i . '" href="">' . $i . '</a> ';
            }
            // 如果总页码大于4，显示省略号和末页链接
            if ($total_pages > 4) {
                $pagination .= '<a class="ellipsis page-link">...</a> ';
                $pagination .= '<a class="page-link" data-page="' . $total_pages . '" href="">' . $total_pages . '</a>';
            }
        } else {
            // 如果当前页码大于4，显示首页链接、省略号、当前页码和末页链接
            $pagination .= '<a class="page-link" data-page="1" href="">1</a> ';
            $pagination .= '<a class="ellipsis page-link">...</a> ';

            // 如果总页码减去当前页码小于4，显示末尾4页的链接
            if ($total_pages - $current_page < 4) {
                for ($i = $total_pages - 3; $i <= $total_pages; $i++) {
                    if ($i > 0) {
                        $pagination .= '<a class="page-link' . ($i == $current_page ? ' active' : '') . '" data-page="' . $i . '" href="">' . $i . '</a> ';
                    }
                }
            } else {
                // 如果总页码减去当前页码大于等于4，显示当前页码、省略号和末页链接
                $pagination .= '<a class="page-link active" data-page="' . $current_page . '" href="">' . $current_page . '</a> ';
                $pagination .= '<a class="ellipsis page-link">...</a> ';
                $pagination .= '<a class="page-link" data-page="' . $total_pages . '" href="">' . $total_pages . '</a>';
            }
        }

        // 如果当前页码小于总页码，显示下一页链接
        if ($current_page < $total_pages) {
            $pagination .= '<a class="page-link next-page" data-page="' . ($current_page + 1) . '" href="">&raquo;</a>';
        }

        $pagination .= '</div>';
        return $pagination;
    }
    return '';
}
?>