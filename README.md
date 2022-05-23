# Starter Content Exporter
------

A WordPress plugin which allows you to build a JSON string with data available for export via REST API.
It offers a UI to select a set of data (posts, taxonomies, widgets, options and theme mods) from the current WordPress site.

## Output?
 
The selected data is available at the endpoint `/wp-json/sce/v1/data` as a JSON object.
 
## About data

@todo Need to update this to the new `must-import` and `optional content/data` structure!

### Images

If you have images under copyright and you don't want to export them, this plugin offers you two fields :

- **Placeholders** – A gallery of images which should replace all the images from post_content, featured image and custom galleries
- **Ignored Images** – A gallery of images which should be ignored from image replacement like logos, favicons or screenshots with examples.

**REST Routes**

- GET – `/wp-json/sce/v1/media` – Allows you to get a data image, encoded in base64, for a giving attachment `id`.

### Post types and Taxonomies

The data JSON has two entries `post_types` and `taxonomies`; each of hold a list of all the post types and taxonomies along  with all the ids selected for export.

**REST Routes**

* POST – `wp-json//sce/v1/posts` – Returns all the post data for all the given ids in the `include` argument
	- `post_type` – default to `post`.
	- `include` – is a list of posts ids separated by comma. 
	- `placeholders` should be a list of attachments ids which should map the selected placeholders with the imported attachments on the client.
	- `ignored_images` should be a list of attachments ids which should map the selected ignored_images with the ones imported on the client.

* GET – `wp-json/sce/v1/terms`
	- Returns all the data for a given `taxonomy` and a list of term ids separated by comma in `include`.
	- It doesn't support image replacement yet
 
### Widgets

The same as for posts, we need to map the placeholders and ignored_images from the source with the ones from the destination.

The structure of the widgets data is inspired by the [Widget Data - Setting Import/Export Plugin](https://wordpress.org/plugins/widget-settings-importexport).

##### @TODO At this moment there is no UI to select which widgets should be exported, they all are!

**REST Routes**

- POST – `/wp-json/sce/v1/widgets` – Returns all the widgets + data
	- `placeholders` should be a list of attachments ids which should map the selected placeholders with the imported attachments on the client.
	- `ignored_images` should be a list of attachements ids which should map the selected ignored_images with the ones imported on the client.

### Settings

WordPress websites relly a lot on Settings API and this means we also need to add a way to export them.

The exported settings are exported in 4 ways.

* **Options** 
	- Pre import options – A collection of options  which should be imported when the import starts
	- Post import options – A collection of options which should be imported at the end of the import

* **Theme Mods**
	- Pre – Before Import
	- Post – After Import

The reason for this split is because some options or theme mods are required before the actual import.
As an example, if the source of the import has the JetPack Portfolio enabled, and the destination of the import doesn't?
 In this case, the import action will fail if we didn't set up as active the Jetpack Content addon as active and the Portfolio checkbox as enabled.

Post Import options should hold option keys which are dependent on imported data.
For example, the Custom Logo field requires the attachment id of an actual attachment, but we need to be sure that we 
already imported that attachment before using it.

##### @TODO Also there is no image replacement for options yet
