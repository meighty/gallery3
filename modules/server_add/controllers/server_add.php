<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2008 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class Server_Add_Controller extends Controller {
  public function index($id) {
    $paths = unserialize(module::get_var("server_add", "authorized_paths"));

    $item = ORM::factory("item", $id);
    access::required("server_add", $item);

    $view = new View("server_add_tree_dialog.html");
    $view->action = url::site("server_add/add_photo/$id");
    $view->hidden = array("csrf" => access::csrf_token(), "base_url" => url::base(true));
    $view->parents = $item->parents();
    $view->album_title = $item->title;

    $tree = new View("server_add_tree.html");
    $tree->data = array();
    $tree->tree_id = "tree_$id";
    foreach (array_keys($paths) as $path) {
      $tree->data[$path] = array("path" => $path, "is_dir" => true);
    }
    $view->tree = $tree->__toString();
    print $view;
  }

  public function children() {
    $path = $this->input->post("path");
    $path = implode("/", $this->input->post("path"));
    if (!is_readable($path)) {
      kohana::show_404();
    }

    $tree = new View("server_add_tree.html");
    $tree->data = $this->_get_children($path);
    $tree->tree_id = "tree_" . md5($path);
    print $tree;
  }

  function start() {
    batch::start();
  }

  function add_photo($id) {
    access::verify_csrf();

    $parent = ORM::factory("item", $id);
    access::required("server_add", $parent);
    if (!$parent->is_album() && !$parent->loaded ) {
      throw new Exception("@todo BAD_ALBUM");
    }

    $path = $this->input->post("path");

    $paths = unserialize(module::get_var("server_add", "authorized_paths"));
    if (empty($paths[$path[0]])) {
      throw new Exception("@todo BAD_PATH");
    }

    $source_path = $path[0];
    // The first path corresponds to the source directory so we can just skip it.
    for ($i = 1; $i < count($path); $i++) {
      $source_path .= "/$path[$i]";
      $pathinfo = pathinfo($source_path);
      set_time_limit(30);
      if (is_dir($source_path)) {
        $album = ORM::factory("item")
          ->where("name", $path[$i])
          ->where("parent_id", $parent->id)
          ->find();
        if (!$album->loaded) {
          $album = album::create($parent, $path[$i], $path[$i], null, user::active()->id);
        }
        $parent = $album;
      } else if (in_array($pathinfo["extension"], array("flv", "mp4"))) {
        $movie = movie::create($parent, $source_path, basename($source_path),
                               basename($source_path), null, user::active()->id);
      } else {
        $photo = photo::create($parent, $source_path, basename($source_path),
                        basename($source_path), null, user::active()->id);
      }
    }
  }

  public function finish() {
    batch::stop();
    print json_encode(array("result" => "success"));
  }

  private function _get_children($path) {
    $file_list = array();
    $files = new DirectoryIterator($path);
    foreach ($files as $file) {
      if ($file->isDot()) {
        continue;
      }
      $filename = $file->getFilename();
      if ($filename[0] != ".") {
        if ($file->isDir()) {
          $file_list[$filename] = array("path" => $file->getPathname(), "is_dir" => true);
        } else {
          $extension = strtolower(substr(strrchr($filename, '.'), 1));
          if ($file->isReadable() &&
              in_array($extension, array("gif", "jpeg", "jpg", "png", "flv", "mp4"))) {
            $file_list[$filename] = array("path" => $file->getPathname(), "is_dir" => false);
          }
        }
      }
    }
    return $file_list;
  }
}