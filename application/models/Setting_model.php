<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Setting_model extends CI_Model {
    public function __construct() {
        parent::__construct();
    }

    private function map_saldo($row) {
        return array(
            'id' => (int) $row->ms_saldo_id,
            'nominal' => (int) $row->ms_saldo_nominal,
            'price' => (int) $row->ms_saldo_price,
            'discount' => (int) $row->ms_saldo_discount,
            'active' => $row->ms_saldo_active,
        );
    }

    // daftar pilihan nominal top up saldo yang tersedia (hanya yang masih aktif), dengan paging
    public function get_saldo_list($page = 1, $per_page = 20) {
        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $this->db->where('ms_saldo_active', 'Y');
        $total_items = (int) $this->db->count_all_results('ms_saldo');
        $total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;

        $this->db->where('ms_saldo_active', 'Y');
        $this->db->order_by('ms_saldo_nominal', 'ASC');
        $this->db->limit($per_page, $offset);
        $query = $this->db->get('ms_saldo');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = $this->map_saldo($row);
        }

        return array(
            'data' => $data,
            'pagination' => array(
                'current_page' => $page,
                'per_page' => $per_page,
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'has_next_page' => $page < $total_pages,
                'has_prev_page' => $page > 1,
            ),
        );
    }

    // daftar pilihan nominal top up saldo yang tersedia (hanya yang masih aktif), tanpa paging
    public function get_saldo_list_no_pagging() {
        $this->db->where('ms_saldo_active', 'Y');
        $this->db->order_by('ms_saldo_nominal', 'ASC');
        $query = $this->db->get('ms_saldo');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = $this->map_saldo($row);
        }
        return $data;
    }

    public function add_saldo($nominal, $price, $discount = 0) {
        $this->db->insert('ms_saldo', array(
            'ms_saldo_nominal' => $nominal,
            'ms_saldo_price' => $price,
            'ms_saldo_discount' => $discount,
            'ms_saldo_active' => 'Y',
        ));
        return $this->db->insert_id();
    }

    public function is_saldo_nominal_exists($nominal, $exclude_id = null) {
        $this->db->where('ms_saldo_nominal', $nominal);
        $this->db->where('ms_saldo_active', 'Y');
        if ($exclude_id) $this->db->where('ms_saldo_id !=', $exclude_id);
        return $this->db->count_all_results('ms_saldo') > 0;
    }

    public function get_saldo_by_id($ms_saldo_id) {
        $this->db->where('ms_saldo_id', $ms_saldo_id);
        return $this->db->get('ms_saldo')->row();
    }

    public function edit_saldo($ms_saldo_id, $data) {
        if (!$this->get_saldo_by_id($ms_saldo_id)) return false;
        $this->db->where('ms_saldo_id', $ms_saldo_id);
        return $this->db->update('ms_saldo', $data);
    }

    public function delete_saldo($ms_saldo_id) {
        if (!$this->get_saldo_by_id($ms_saldo_id)) return false;
        $this->db->where('ms_saldo_id', $ms_saldo_id);
        return $this->db->update('ms_saldo', array('ms_saldo_active' => 'N'));
    }

    private function map_category_meja($row) {
        return array(
            'id' => (int) $row->category_meja_id,
            'name' => $row->category_meja_name,
            'price' => (int) $row->category_meja_price,
            'active' => $row->category_meja_active,
        );
    }

    // list kategori meja yang masih aktif
    public function get_category_meja_list($page = 1, $per_page = 20) {
        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $this->db->where('category_meja_active', 'Y');
        $total_items = (int) $this->db->count_all_results('category_meja');
        $total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;

        $this->db->where('category_meja_active', 'Y');
        $this->db->order_by('category_meja_id', 'ASC');
        $this->db->limit($per_page, $offset);
        $query = $this->db->get('category_meja');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = $this->map_category_meja($row);
        }

        return array(
            'data' => $data,
            'pagination' => array(
                'current_page' => $page,
                'per_page' => $per_page,
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'has_next_page' => $page < $total_pages,
                'has_prev_page' => $page > 1,
            ),
        );
    }

    public function add_category_meja($name, $price = 1) {
        $data = array(
            'category_meja_name' => $name,
            'category_meja_price' => $price,
            'category_meja_active' => 'Y',
        );
        $this->db->insert('category_meja', $data);
        return $this->db->insert_id();
    }

    public function get_category_meja_by_id($category_meja_id) {
        $this->db->where('category_meja_id', $category_meja_id);
        return $this->db->get('category_meja')->row();
    }

    public function is_category_meja_name_exists($name, $exclude_id = null) {
        $this->db->where('category_meja_name', $name);
        $this->db->where('category_meja_active', 'Y');
        if ($exclude_id) $this->db->where('category_meja_id !=', $exclude_id);
        return $this->db->count_all_results('category_meja') > 0;
    }

    public function edit_category_meja($category_meja_id, $data) {
        if (!$this->get_category_meja_by_id($category_meja_id)) return false;
        $this->db->where('category_meja_id', $category_meja_id);
        return $this->db->update('category_meja', $data);
    }

    public function delete_category_meja($category_meja_id) {
        if (!$this->get_category_meja_by_id($category_meja_id)) return false;
        $this->db->where('category_meja_id', $category_meja_id);
        return $this->db->update('category_meja', array('category_meja_active' => 'N'));
    }
}

?>