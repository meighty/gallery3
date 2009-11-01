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
class recaptcha_event_Core {
  static function extend_form($event_data) {
    // @todo put into an admin interface
    $config = Kohana::config("recaptcha");
    if (module::get_var("recaptcha", "public_key") &&
        in_array($event_data->id, $config["recaptcha"])) {
      $input = empty($event_data->append_to) ? $event_data->form : $event_data->append_to;
      $input->recaptcha("recaptcha")->label("")->id("g-recaptcha");
    }
  }

  static function admin_menu($menu, $theme) {
    $menu->get("settings_menu")
      ->append(Menu::factory("link")
               ->id("recaptcha")
               ->label(t("reCAPTCHA"))
               ->url(url::site("admin/recaptcha")));
  }
}
