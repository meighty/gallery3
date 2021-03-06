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
/**
 * Proxy access to files in var/albums and var/resizes, making sure that the session user has
 * access to view these files.
 *
 * Security Philosophy: we do not use the information provided to find if the file exists on
 * disk.  We use this information only to locate the correct item in the database and then we
 * *only* use information from the database to find and proxy the correct file.  This way all user
 * input is sanitized against the database before we perform any file I/O.
 */
class File_Proxy_Controller extends Controller {
  public function __call($function, $args) {
    // request_uri: gallery3/var/trunk/albums/foo/bar.jpg
    $request_uri = rawurldecode(Input::instance()->server("REQUEST_URI"));

    $request_uri = preg_replace("/\?.*/", "", $request_uri);

    // var_uri: gallery3/var/
    $var_uri = url::file("var/");

    // Make sure that the request is for a file inside var
    $offset = strpos(rawurldecode($request_uri), $var_uri);
    if ($offset !== 0) {
      throw new Kohana_404_Exception();
    }

    $file_uri = substr($request_uri, strlen($var_uri));

    // Make sure that we don't leave the var dir
    if (strpos($file_uri, "..") !== false) {
      throw new Kohana_404_Exception();
    }

    list ($type, $path) = explode("/", $file_uri, 2);
    if ($type != "resizes" && $type != "albums" && $type != "thumbs") {
      throw new Kohana_404_Exception();
    }

    // If the last element is .album.jpg, pop that off since it's not a real item
    $path = preg_replace("|/.album.jpg$|", "", $path);
    $encoded_path = array();
    foreach (explode("/", $path) as $path_part) {
      $encoded_path[] = rawurlencode($path_part);
    }

    // We now have the relative path to the item.  Search for it in the path cache
    // The patch cache is urlencoded so re-encode the path. (it was decoded earlier to
    // insure that the paths are normalized.
    $item = ORM::factory("item")
      ->where("relative_path_cache", "=", implode("/", $encoded_path))->find();
    if (!$item->loaded()) {
      // We didn't turn it up.  It's possible that the relative_path_cache is out of date here.
      // There was fallback code, but bharat deleted it in 8f1bca74.  If it turns out to be
      // necessary, it's easily resurrected.

      // If we're looking for a .jpg then it's it's possible that we're requesting the thumbnail
      // for a movie.  In that case, the .flv or .mp4 file would have been converted to a .jpg.
      // So try some alternate types:
      if (preg_match('/.jpg$/', $path)) {
        foreach (array("flv", "mp4") as $ext) {
          $movie_path = preg_replace('/.jpg$/', ".$ext", $path);
          $item = ORM::factory("item")->where("relative_path_cache", "=", $movie_path)->find();
          if ($item->loaded()) {
            break;
          }
        }
      }
    }

    if (!$item->loaded()) {
      throw new Kohana_404_Exception();
    }

    // Make sure we have access to the item
    if (!access::can("view", $item)) {
      throw new Kohana_404_Exception();
    }

    // Make sure we have view_full access to the original
    if ($type == "albums" && !access::can("view_full", $item)) {
      throw new Kohana_404_Exception();
    }

    // Don't try to load a directory
    if ($type == "albums" && $item->is_album()) {
      throw new Kohana_404_Exception();
    }

    if ($type == "albums") {
      $file = $item->file_path();
    } else if ($type == "resizes") {
      $file = $item->resize_path();
    } else {
      $file = $item->thumb_path();
    }

    if (!file_exists($file)) {
      throw new Kohana_404_Exception();
    }

    header("Pragma:");
    // Check that the content hasn't expired or it wasn't changed since cached
    expires::check(2592000, $item->updated);

    // We don't need to save the session for this request
    Session::abort_save();

    expires::set(2592000, $item->updated);  // 30 days

    // Dump out the image.  If the item is a movie, then its thumbnail will be a JPG.
    if ($item->is_movie() && $type != "albums") {
      header("Content-type: image/jpeg");
    } else {
      header("Content-Type: $item->mime_type");
    }

    Kohana::close_buffers(false);
    $fd = fopen($file, "rb");
    fpassthru($fd);
    fclose($fd);
  }
}
