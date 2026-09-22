<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Access_model extends CI_Model {
    public function __construct() {
        parent::__construct();
    }

    /**
     * Role
     */

    public function get_role_list($page = 1, $per_page = 20) {
        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $this->db->where('role_active', 'Y');
        $total_items = (int) $this->db->count_all_results('ms_role');
        $total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;

        $this->db->where('role_active', 'Y');
        $this->db->order_by('role_id', 'ASC');
        $this->db->limit($per_page, $offset);
        $query = $this->db->get('ms_role');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'id' => (int) $row->role_id,
                'name' => $row->role_name,
                'active' => $row->role_active,
            );
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

    public function get_role_list_no_pagging() {
        $this->db->where('role_active', 'Y');
        $this->db->order_by('role_id', 'ASC');
        $query = $this->db->get('ms_role');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'id' => (int) $row->role_id,
                'name' => $row->role_name,
                'active' => $row->role_active,
            );
        }
        return $data;
    }

    public function get_role_by_id($role_id) {
        $this->db->where('role_id', $role_id);
        return $this->db->get('ms_role')->row();
    }

    public function is_role_name_exists($name, $exclude_id = null) {
        $this->db->where('role_name', $name);
        $this->db->where('role_active', 'Y');
        if ($exclude_id) $this->db->where('role_id !=', $exclude_id);
        return $this->db->count_all_results('ms_role') > 0;
    }

    public function add_role($name) {
        $this->db->insert('ms_role', array(
            'role_name' => $name,
            'role_active' => 'Y',
        ));
        return $this->db->insert_id();
    }

    public function edit_role($role_id, $data) {
        if (!$this->get_role_by_id($role_id)) return false;
        $this->db->where('role_id', $role_id);
        return $this->db->update('ms_role', $data);
    }

    public function delete_role($role_id) {
        if (!$this->get_role_by_id($role_id)) return false;
        $this->db->where('role_id', $role_id);
        return $this->db->update('ms_role', array('role_active' => 'N'));
    }

    public function is_role_in_use($role_id) {
        $this->db->where('userrole', $role_id);
        $this->db->where('is_active', 'Y');
        return $this->db->count_all_results('ms_user') > 0;
    }

    /**
     * Menu
     */

    public function get_menu_list_no_pagging() {
        $this->db->where('menu_active', 'Y');
        $this->db->order_by('menu_id', 'ASC');
        $query = $this->db->get('ms_menu');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'id' => (int) $row->menu_id,
                'code' => $row->menu_code,
                'name' => $row->menu_name,
                'active' => $row->menu_active,
            );
        }
        return $data;
    }

    public function get_menu_by_id($menu_id) {
        $this->db->where('menu_id', $menu_id);
        return $this->db->get('ms_menu')->row();
    }

    public function is_menu_code_exists($menu_code) {
        $this->db->where('menu_code', $menu_code);
        return $this->db->count_all_results('ms_menu') > 0;
    }

    public function is_menu_name_exists($name, $exclude_id = null) {
        $this->db->where('menu_name', $name);
        $this->db->where('menu_active', 'Y');
        if ($exclude_id) $this->db->where('menu_id !=', $exclude_id);
        return $this->db->count_all_results('ms_menu') > 0;
    }

    public function add_menu($code, $name) {
        $this->db->insert('ms_menu', array(
            'menu_code' => $code,
            'menu_name' => $name,
            'menu_active' => 'Y',
        ));
        return $this->db->insert_id();
    }

    public function edit_menu($menu_id, $data) {
        if (!$this->get_menu_by_id($menu_id)) return false;
        $this->db->where('menu_id', $menu_id);
        return $this->db->update('ms_menu', $data);
    }

    public function delete_menu($menu_id) {
        if (!$this->get_menu_by_id($menu_id)) return false;
        $this->db->where('menu_id', $menu_id);
        return $this->db->update('ms_menu', array('menu_active' => 'N'));
    }

    /**
     * Role Access (hak akses)
     */

    // hak akses 1 role di semua menu aktif. menu yang belum ada baris role_access-nya dianggap semua akses 'N'
    public function get_role_access($role_id) {
        $this->db->select('m.menu_id, m.menu_code, m.menu_name, ra.can_view, ra.can_add, ra.can_edit, ra.can_delete', false);
        $this->db->from('ms_menu m');
        $this->db->join('role_access ra', 'ra.menu_id = m.menu_id AND ra.role_id = ' . (int) $role_id, 'left');
        $this->db->where('m.menu_active', 'Y');
        $this->db->order_by('m.menu_id', 'ASC');
        $query = $this->db->get();

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'menu_id' => (int) $row->menu_id,
                'menu_code' => $row->menu_code,
                'menu_name' => $row->menu_name,
                'can_view' => $row->can_view !== null ? $row->can_view : 'N',
                'can_add' => $row->can_add !== null ? $row->can_add : 'N',
                'can_edit' => $row->can_edit !== null ? $row->can_edit : 'N',
                'can_delete' => $row->can_delete !== null ? $row->can_delete : 'N',
            );
        }
        return $data;
    }

    // simpan/update hak akses 1 role untuk banyak menu sekaligus (upsert per menu_id)
    public function update_role_access($role_id, $access_list) {
        $this->db->trans_start();

        foreach ($access_list as $access) {
            $menu_id = (int) $access['menu_id'];
            if (!$this->get_menu_by_id($menu_id)) continue;

            $row = array(
                'can_view' => isset($access['can_view']) && in_array($access['can_view'], array('Y', 'N')) ? $access['can_view'] : 'N',
                'can_add' => isset($access['can_add']) && in_array($access['can_add'], array('Y', 'N')) ? $access['can_add'] : 'N',
                'can_edit' => isset($access['can_edit']) && in_array($access['can_edit'], array('Y', 'N')) ? $access['can_edit'] : 'N',
                'can_delete' => isset($access['can_delete']) && in_array($access['can_delete'], array('Y', 'N')) ? $access['can_delete'] : 'N',
            );

            $this->db->where('role_id', $role_id);
            $this->db->where('menu_id', $menu_id);
            $existing = $this->db->get('role_access')->row();

            if ($existing) {
                $this->db->where('role_access_id', $existing->role_access_id);
                $this->db->update('role_access', $row);
            } else {
                $row['role_id'] = $role_id;
                $row['menu_id'] = $menu_id;
                $this->db->insert('role_access', $row);
            }
        }

        $this->db->trans_complete();
        return $this->db->trans_status();
    }

    /**
     * Hak akses milik 1 user yang login: userrole (ms_user) -> role -> daftar akses per menu
     */
    public function get_user_access($user_id) {
        $this->db->select('user_id, username, userrole');
        $this->db->where('user_id', $user_id);
        $this->db->where('is_active', 'Y');
        $user = $this->db->get('ms_user')->row();
        if (!$user) return null;

        $role = $this->get_role_by_id($user->userrole);

        return array(
            'user_id' => (int) $user->user_id,
            'username' => $user->username,
            'role_id' => (int) $user->userrole,
            'role_name' => $role ? $role->role_name : null,
            'access' => $this->get_role_access($user->userrole),
        );
    }
}

?>
