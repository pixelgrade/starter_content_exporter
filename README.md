# Starter Content Exporter
------

A WordPress plugin which allows you to build a json string with data available for export via REST API.
It offers an UI to select a set of data (posts, taxonomies, widgets, options and theme mods) from the current WordPress site.

## Output?
 
The selected data is available at the endpoint `/wp-json/sce/v1/data` as a JSON object.
 
## About data
 
### Images

If you have images under copyright and you don't want to export them, this plugin offers you two fields :

- **Placeholders** – A gallery of images which should replace all the images from post_content, featured images and custom galleries
- **Ignored Images** – A gallery of images which should ignored from replacement like logo, favicon or screenshots with examples.

### Widgets

To avoid encoding issues, we decided to output widgets as a base64 string.
The structure of the widgets data is inspired by the [Widget Data - Setting Import/Export Plugin](https://wordpress.org/plugins/widget-settings-importexport)

#####@TODO At this moment there is no UI to select which widgets should be exported, they all are!

### Settings

WordPress websites relly a lot on Settings API and this means we also need to add a way to export them.

The exported settings are exported in 4 ways.

* **Options** 
	- Pre import options – A set of options  which should be imported when the import starts
	- Post import options – A set of options which should be imported at the end of the import

* **Theme Mods**
	- Pre – Before Import
	- Post – After Import

The reason for this split is because some options or theme mods are required before the actual import.
As example, if the source of the import has the JetPack Portfolio enabled, and the destination of the import doesn't?
 In this case the import action will fail if we didn't set up as active the Jetpack Content addon as active and the Portfolio checkbox as enabled.

Post Import options should hold option keys which are dependend of imported data.
For example, the Custom Logo field requires the attachment id of an actual attachment but we need to be sure that we 
already imported that attachment before using it.


#####@TODO At this moment there is no UI for selecting which option keys or theme mods should be ignored or selected in Pre or Post import event.
