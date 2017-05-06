var plugin = 'starter_content_exporter',
	source_SCSS = { admin: './scss/**/*.scss', public: './scss/**/*.scss'},
	dest_CSS = { admin:'./css/', public: './css/'},

	gulp 		= require('gulp'),
	exec 		= require('gulp-exec'),
	replace 	= require('gulp-replace'),
	concat 		= require('gulp-concat'),
	notify 		= require('gulp-notify'),
	chmod 		= require('gulp-chmod'),
	fs          = require('fs'),
	del         = require('del'),
	rename 		= require('gulp-rename');

require('es6-promise').polyfill();

var options = {
	silent: true,
	continueOnError: true // default: false
};

/**
 * Create a zip archive out of the cleaned folder and delete the folder
 */
gulp.task( 'zip', ['build'], function() {
	return gulp.src( './' )
		.pipe( exec( 'cd ./../; rm -rf starter_content_exporter.zip; cd ./build/; zip -r -X ./../starter_content_exporter.zip ./starter_content_exporter; cd ./../; rm -rf build' ) );

} );

/**
 * Copy theme folder outside in a build folder, recreate styles before that
 */
gulp.task( 'copy-folder', function() {
	return gulp.src( './' )
		.pipe( exec( 'rm -Rf ./../build; mkdir -p ./../build/starter_content_exporter; cp -Rf ./* ./../build/starter_content_exporter/' ) );
} );

/**
 * Clean the folder of unneeded files and folders
 */
gulp.task( 'build', ['copy-folder'], function() {

	// files that should not be present in build zip
	files_to_remove = [
		'node_modules',
		'tests',
		'.travis.yml',
		'circle.yml',
		'.sass-cache',
		'gulpfile.js',
		'package.json',
		'build',
		'.idea',
		'**/*.css.map',
		'**/.git*',
		'*.sublime-project',
		'.DS_Store',
		'**/.DS_Store',
		'__MACOSX',
		'**/__MACOSX',
		'README.md',
		'socket/node_modules',
		'socket/src',
		'socket/scss',
		'socket/.babelrc',
		'socket/gulpfile.js',
		'socket/package.json',
		'socket/semantic.json',
	];

	files_to_remove.forEach( function( e, k ) {
		files_to_remove[k] = '../build/starter_content_exporter/' + e;
	} );

	del.sync( files_to_remove, { force: true } );
} );
