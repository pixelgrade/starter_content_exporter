# Framework In development

Socket offers a simple and extensible drag and drop interface to define your theme or plugin options. Based on the REST API, Socket is easily extensible and already support a wide range fields as: select, input or autocomplete.

Easily built your Plugin Options page, place the exported files inside your plugin folder and that’s it — all your options ready to be used.

### Wishful thinking

At the version one this repository will stand as a WordPress framework for plugins options. Pretty much something like Options Framework or Redux, Option tree or whatever is out there in the world.

But the road is long.

## Why in the hell another options framework??

Because I can!

And mainly because I'm thinking to use:
 * React and [React Semantic-UI](http://react.semantic-ui.com/)
 * The new WordPress REST API to handle the data
 
Isn't this the next Big thing in web apps and WordPress dashboard? A headless dashboard? Don't believe me? Ask Matt!

## Install and develop

Well, simply `npm install` and you are ready to run

`gulp styles` – to build styles

`gulp compile` – to build from react to ES5

`gulp watch` - if you don't want to bother too much with the terminal

For the moment this is just an example plugin, the config is editable (or filtrable) and any idea is welcomed

