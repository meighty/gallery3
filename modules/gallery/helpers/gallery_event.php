<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2009 Bharat Mediratta
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

class gallery_event_Core {
  /**
   * Initialization.
   */
  static function gallery_ready() {
    identity::load_user();
    theme::load_themes();
    locales::set_request_locale();
  }

  static function user_deleted($user) {
    $admin = identity::admin_user();
    if (!empty($admin)) {          // could be empty if there is not identity provider
      db::build()
        ->update("tasks")
        ->set("owner_id", $admin->id)
        ->where("owner_id", "=", $user->id)
        ->execute();
      db::build()
        ->update("items")
        ->set("owner_id", $admin->id)
        ->where("owner_id", "=", $user->id)
        ->execute();
      db::build()
        ->update("logs")
        ->set("user_id", $admin->id)
        ->where("user_id", "=", $user->id)
        ->execute();
    }
  }

  static function identity_provider_changed($old_provider, $new_provider) {
    $admin = identity::admin_user();
    db::build()
      ->update("tasks")
      ->set("owner_id", $admin->id)
      ->execute();
    db::build()
      ->update("items")
      ->set("owner_id", $admin->id)
      ->execute();
    db::build()
      ->update("logs")
      ->set("user_id", $admin->id)
      ->execute();
  }

  static function group_created($group) {
    access::add_group($group);
  }

  static function group_deleted($group) {
    access::delete_group($group);
  }

  static function item_created($item) {
    access::add_item($item);
  }

  static function item_deleted($item) {
    access::delete_item($item);
  }

  static function item_moved($item, $old_parent) {
    access::recalculate_permissions($item->parent());
  }

  static function user_login($user) {
    // If this user is an admin, check to see if there are any post-install tasks that we need
    // to run and take care of those now.
    if ($user->admin && module::get_var("gallery", "choose_default_tookit", null)) {
      graphics::choose_default_toolkit();
      module::clear_var("gallery", "choose_default_tookit");
    }
  }

  static function item_index_data($item, $data) {
    $data[] = $item->description;
    $data[] = $item->name;
    $data[] = $item->title;
  }

  static function user_menu($menu, $theme) {
    if ($theme->page_subtype != "login") {
      $user = identity::active_user();
      if ($user->guest) {
        $menu->append(Menu::factory("dialog")
                      ->id("user_menu_login")
                      ->css_id("g-login-link")
                      ->url(url::site("login/ajax"))
                      ->label(t("Login")));
      } else {
        $csrf = access::csrf_token();
        $menu->append(Menu::factory("link")
                      ->id("user_menu_edit_profile")
                      ->css_id("g-user-profile-link")
                      ->view("login_current_user.html")
                      ->url(user_profile::url($user->id))
                      ->label($user->display_name()));
        $menu->append(Menu::factory("link")
                      ->id("user_menu_logout")
                      ->css_id("g-logout-link")
                      ->url(url::site("logout?csrf=$csrf&amp;continue=" .
                                      urlencode(url::abs_current())))
                      ->label(t("Logout")));
      }
    }
  }

  static function site_menu($menu, $theme) {
    if ($theme->page_subtype != "login") {
      $menu->append(Menu::factory("link")
                    ->id("home")
                    ->label(t("Home"))
                    ->url(item::root()->url()));


      $item = $theme->item();

      if (!empty($item)) {
        $can_edit = $item && access::can("edit", $item);
        $can_add = $item && access::can("add", $item);

        if ($can_add) {
          $menu->append($add_menu = Menu::factory("submenu")
                        ->id("add_menu")
                        ->label(t("Add")));
          $is_album_writable =
            is_writable($item->is_album() ? $item->file_path() : $item->parent()->file_path());
          if ($is_album_writable) {
            $add_menu->append(Menu::factory("dialog")
                              ->id("add_photos_item")
                              ->label(t("Add photos"))
                              ->url(url::site("simple_uploader/app/$item->id")));
            if ($item->is_album()) {
              $add_menu->append(Menu::factory("dialog")
                                ->id("add_album_item")
                                ->label(t("Add an album"))
                                ->url(url::site("form/add/albums/$item->id?type=album")));
            }
          } else {
            message::warning(t("The album '%album_name' is not writable.",
                               array("album_name" => $item->title)));
          }
        }

        switch ($item->type) {
        case "album":
          $option_text = t("Album options");
          $edit_text = t("Edit album");
          break;
        case "movie":
          $option_text = t("Movie options");
          $edit_text = t("Edit movie");
          break;
        default:
          $option_text = t("Photo options");
          $edit_text = t("Edit photo");
        }

        $menu->append($options_menu = Menu::factory("submenu")
                      ->id("options_menu")
                      ->label($option_text));
        if ($item && ($can_edit || $can_add)) {
          if ($can_edit) {
            $options_menu->append(Menu::factory("dialog")
                                  ->id("edit_item")
                                  ->label($edit_text)
                                  ->url(url::site("form/edit/{$item->type}s/$item->id")));
          }

          if ($item->is_album()) {
            if ($can_edit) {
              $options_menu->append(Menu::factory("dialog")
                                    ->id("edit_permissions")
                                    ->label(t("Edit permissions"))
                                    ->url(url::site("permissions/browse/$item->id")));
            }
          }
        }
      }

      if (identity::active_user()->admin) {
        $menu->append($admin_menu = Menu::factory("submenu")
                ->id("admin_menu")
                ->label(t("Admin")));
        module::event("admin_menu", $admin_menu, $theme);
      }
    }
  }

  static function admin_menu($menu, $theme) {
    $menu
      ->append(Menu::factory("link")
               ->id("dashboard")
               ->label(t("Dashboard"))
               ->url(url::site("admin")))
      ->append(Menu::factory("submenu")
               ->id("settings_menu")
               ->label(t("Settings"))
               ->append(Menu::factory("link")
                        ->id("graphics_toolkits")
                        ->label(t("Graphics"))
                        ->url(url::site("admin/graphics")))
               ->append(Menu::factory("link")
                        ->id("languages")
                        ->label(t("Languages"))
                        ->url(url::site("admin/languages")))
               ->append(Menu::factory("link")
                        ->id("advanced")
                        ->label(t("Advanced"))
                        ->url(url::site("admin/advanced_settings"))))
      ->append(Menu::factory("link")
               ->id("modules")
               ->label(t("Modules"))
               ->url(url::site("admin/modules")))
      ->append(Menu::factory("submenu")
               ->id("content_menu")
               ->label(t("Content")))
      ->append(Menu::factory("submenu")
               ->id("appearance_menu")
               ->label(t("Appearance"))
               ->append(Menu::factory("link")
                        ->id("themes")
                        ->label(t("Theme choice"))
                        ->url(url::site("admin/themes")))
               ->append(Menu::factory("link")
                        ->id("theme_options")
                        ->label(t("Theme options"))
                        ->url(url::site("admin/theme_options")))
               ->append(Menu::factory("link")
                        ->id("sidebar")
                        ->label(t("Manage sidebar"))
                        ->url(url::site("admin/sidebar"))))
      ->append(Menu::factory("submenu")
               ->id("statistics_menu")
               ->label(t("Statistics")))
      ->append(Menu::factory("link")
               ->id("maintenance")
               ->label(t("Maintenance"))
               ->url(url::site("admin/maintenance")));
    return $menu;
  }

  static function context_menu($menu, $theme, $item, $thumb_css_selector) {
    $menu->append($options_menu = Menu::factory("submenu")
                  ->id("options_menu")
                  ->label(t("Options"))
                  ->css_class("ui-icon-carat-1-n"));

    if (access::can("edit", $item)) {
      switch ($item->type) {
      case "movie":
        $edit_title = t("Edit this movie");
        $delete_title = t("Delete this movie");
        break;

      case "album":
        $edit_title = t("Edit this album");
        $delete_title = t("Delete this album");
        break;

      default:
        $edit_title = t("Edit this photo");
        $delete_title = t("Delete this photo");
        break;
      }
      $cover_title = t("Choose as the album cover");
      $move_title = t("Move to another album");

      $csrf = access::csrf_token();

      $theme_item = $theme->item();
      $options_menu->append(Menu::factory("dialog")
                            ->id("edit")
                            ->label($edit_title)
                            ->css_class("ui-icon-pencil")
                            ->url(url::site("quick/form_edit/$item->id?from_id=$theme_item->id")));

      if ($item->is_photo() && graphics::can("rotate")) {
        $options_menu
          ->append(
            Menu::factory("ajax_link")
            ->id("rotate_ccw")
            ->label(t("Rotate 90° counter clockwise"))
            ->css_class("ui-icon-rotate-ccw")
            ->ajax_handler("function(data) { " .
                           "\$.gallery_replace_image(data, \$('$thumb_css_selector')) }")
            ->url(url::site("quick/rotate/$item->id/ccw?csrf=$csrf&from_id=$theme_item->id")))
          ->append(
            Menu::factory("ajax_link")
            ->id("rotate_cw")
            ->label(t("Rotate 90° clockwise"))
            ->css_class("ui-icon-rotate-cw")
            ->ajax_handler("function(data) { " .
                           "\$.gallery_replace_image(data, \$('$thumb_css_selector')) }")
            ->url(url::site("quick/rotate/$item->id/cw?csrf=$csrf&from_id=$theme_item->id")));
      }

      // @todo Don't move photos from the photo page; we don't yet have a good way of redirecting
      // after move
      if ($theme->page_subtype() == "album") {
        $options_menu
          ->append(Menu::factory("dialog")
                   ->id("move")
                   ->label($move_title)
                   ->css_class("ui-icon-folder-open")
                   ->url(url::site("move/browse/$item->id")));
      }

      $parent = $item->parent();
      if (access::can("edit", $parent)) {
        // We can't make this item the highlight if it's an album with no album cover, or if it's
        // already the album cover.
        if (($item->type == "album" && empty($item->album_cover_item_id)) ||
            ($item->type == "album" && $parent->album_cover_item_id == $item->album_cover_item_id) ||
            $parent->album_cover_item_id == $item->id) {
          $disabledState = " ui-state-disabled";
        } else {
          $disabledState = " ";
        }
        if ($item->parent()->id != 1) {
          $options_menu
            ->append(Menu::factory("ajax_link")
                     ->id("make_album_cover")
                     ->label($cover_title)
                     ->css_class("ui-icon-star")
                     ->ajax_handler("function(data) { window.location.reload() }")
                     ->url(url::site("quick/make_album_cover/$item->id?csrf=$csrf")));
        }
        $options_menu
          ->append(Menu::factory("dialog")
                   ->id("delete")
                   ->label($delete_title)
                   ->css_class("ui-icon-trash")
                   ->css_id("g-quick-delete")
                   ->url(url::site("quick/form_delete/$item->id?csrf=$csrf&from_id=$theme_item->id")));
      }

      if ($item->is_album()) {
        $options_menu
          ->append(Menu::factory("dialog")
                   ->id("add_item")
                   ->label(t("Add a photo"))
                   ->css_class("ui-icon-plus")
                   ->url(url::site("simple_uploader/app/$item->id")))
          ->append(Menu::factory("dialog")
                   ->id("add_album")
                   ->label(t("Add an album"))
                   ->css_class("ui-icon-note")
                   ->url(url::site("form/add/albums/$item->id?type=album")))
          ->append(Menu::factory("dialog")
                   ->id("edit_permissions")
                   ->label(t("Edit permissions"))
                   ->css_class("ui-icon-key")
                   ->url(url::site("permissions/browse/$item->id")));
      }
    }
  }

  static function show_user_profile($data) {
    $v = new View("user_profile_info.html");

    $fields = array("name" => t("Name"), "locale" => t("Locale"), "email" => t("Email"),
                    "full_name" => t("Full name"), "url" => "Web site");
    if (!$data->display_all) {
      $fields = array("name" => t("Name"), "full_name" => t("Full name"), "url" => "Web site");
    }
    $v->fields = array();
    foreach ($fields as $field => $label) {
      if (!empty($data->user->$field)) {
        $v->fields[(string)$label->for_html()] = $data->user->$field;
      }
    }
    $data->content[] = (object)array("title" => t("User information"), "view" => $v);

  }
}
