<?php
function renderImages($mysqli, $items_per_page, $offset) {
    $query = "SELECT * FROM images ORDER BY id DESC LIMIT ? OFFSET ?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param("ii", $items_per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    $images = [];
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $images[] = $row;
        }
    }
    return $images;
}

function renderPagination($current_page, $total_pages, $max_display_pages = 3) {
    if ($total_pages > 1) {
        $pagination = '<div class="pagination">';

        if ($current_page > 1) {
            $pagination .= '<a class="page-link prev-page" data-page="' . ($current_page - 1) . '" href="">&laquo;</a> ';
        }

        // 显示首页
        $pagination .= '<a class="page-link' . ($current_page == 1 ? ' active' : '') . '" data-page="1" href="">1</a> ';

        // 如果当前页码大于5，显示省略号
        if ($current_page > 5) {
            $pagination .= '<a class="ellipsis page-link">...</a> ';
        }

        // 计算中间页码的开始和结束
        $start_page = max(2, $current_page - intval($max_display_pages / 2));
        $end_page = min($total_pages - 1, $start_page + $max_display_pages - 1);
        $start_page = max(2, $end_page - $max_display_pages + 1);

        // 显示中间页码
        for ($i = $start_page; $i <= $end_page; $i++) {
            $pagination .= '<a class="page-link' . ($i == $current_page ? ' active' : '') . '" data-page="' . $i . '" href="">' . $i . '</a> ';
        }

        // 如果总页码超过五，并且当前页码小于倒数第二页，显示省略号
        if ($total_pages > 5 && $current_page < $total_pages - 1) {
            $pagination .= '<a class="ellipsis page-link">...</a> ';
        }

        // 显示末页
        $pagination .= '<a class="page-link' . ($current_page == $total_pages ? ' active' : '') . '" data-page="' . $total_pages . '" href="">' . $total_pages . '</a>';

        if ($current_page < $total_pages) {
            $pagination .= '<a class="page-link next-page" data-page="' . ($current_page + 1) . '" href="">&raquo;</a>';
        }

        $pagination .= '</div>';
        return $pagination;
    }
    return '';
}
?>