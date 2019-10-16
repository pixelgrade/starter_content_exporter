var plugin = 'socket',
	source_SCSS = { admin: './scss/**/*.scss', public: './scss/**/*.scss'},
	dest_CSS = { admin:'./css/', public: './css/'},

	gulp 		= require('gulp'),
	sass 		= require('gulp-sass'),
	prefix 		= require('gulp-autoprefixer'),
	exec 		= require('gulp-exec'),
	del         = require('del');

require('es6-promise').polyfill();


var options = {
	silent: true,
	continueOnError: true // default: false
};

// styles related
gulp.task('styles-admin', function () {
	return gulp.src(source_SCSS.admin)
		.pipe(sass({'sourcemap': false, style: 'compact'}))
			.on('error', function (e) {
				console.log(e.message);
			})
		.pipe(prefix("last 1 version", "> 1%", "ie 8", "ie 7"))
		.pipe(gulp.dest(dest_CSS.admin));
});

gulp.task('styles-public', function () {
	return gulp.src(source_SCSS.public)
		.pipe(sass({'sourcemap': false, style: 'compact'}))
		.on('error', function (e) {
			console.log(e.message);
		})
		.pipe(prefix("last 1 version", "> 1%", "ie 8", "ie 7"))
		.pipe(gulp.dest(dest_CSS.public));
});

gulp.task('styles', function (cb) {
	return gulp.series( 'styles-admin', 'styles-public' )(cb);
});

gulp.task('watch-styles', function () {
	return gulp.watch(source_SCSS.admin, ['styles-admin']);
});

gulp.task('watch-public', function () {
	return gulp.watch(source_SCSS.public, ['styles-public']);
});

var sourcemaps = require('gulp-sourcemaps');
var source = require('vinyl-source-stream');
var buffer = require('vinyl-buffer');
var browserify = require('browserify');
var watchify = require('watchify');
var babel = require('babelify');

function compile_admin(watch) {
	var bundler = browserify('./src/socket.js', { debug: true }).transform(babel, { presets: ["es2015", "stage-0", "react"]});

	function rebundle_admin() {
		return bundler.bundle()
			.on('error', function(err) { console.error(err); this.emit('end'); })
			.pipe(source('socket.js'))
			.pipe(buffer())
			.pipe(sourcemaps.init({ loadMaps: true }))
			.pipe(sourcemaps.write('./'))
			.pipe(gulp.dest('./js'));
	}

	if (watch) {
		bundler.on('update', function() {
			console.log('-> bundling admin dashboard...' + new Date().getTime() / 1000);
			rebundle_admin();
		});
	}
	return rebundle_admin();
}

function watch_admin() {
	return compile_admin(true);
}

gulp.task('react', function() { return compile(); });
gulp.task('compile', function() { return compile_admin(false); });
