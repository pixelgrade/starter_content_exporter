import React from "react"
import PropTypes from 'prop-types'

import {
	Dropdown,
	Form
} from 'semantic-ui-react'
import isEmpty from 'lodash/isEmpty'
import isUndefined from 'lodash/isUndefined'

class SocketPostSelect extends React.Component {

	constructor(props) {
		// this makes the this
		super(props);

		// get the current state localized by wordpress
		this.state = {
			loading: true,
			posts: [],
			name: null,
			value: this.props.value
		};

		this.handleChange = this.handleChange.bind(this);
	}

	render() {
		let component = this,
			output = null,
			value = this.props.value,
			placeholder = this.props.placeholder || 'Select';

		if ( isEmpty( value ) ) {
			value = []
		}

		output = <Form.Field className="post_type_select" >
			<Dropdown
				placeholder={placeholder}
				fluid
				multiple
				search
				selection
				closeOnBlur
				closeOnEscape
				loading={this.state.loading}
				defaultValue={value}
				options={this.state.posts}
				onChange={component.handleChange}
			/>
		</Form.Field>

		return output;
	}

	handleChange = (e, { value }) => {
		let component = this

		component.props.setup_loading_flag( true )

		jQuery.ajax({
			url: socket.wp_rest.root + socket.wp_rest.api_base + '/option',
			method: 'POST',
			beforeSend: function (xhr) {
				xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce);
			},
			data: {
				'socket_nonce': socket.wp_rest.socket_nonce,
				name: this.props.name,
				value: value
			}
		}).done(function (response) {
			component.props.setup_loading_flag( false );
		}).error(function (err) {
			console.log(err);
			component.props.setup_loading_flag( false );
		});

		this.setState({ value });
	}

	componentWillMount(){
		if ( ! this.state.loading ) {
			return false;
		}

		let component = this;

		// load all the posts
		wp.api.loadPromise.done( function() {
			let wpPosts = new wp.api.collections.Posts(),
				posts = [],
				query = {};

			if ( ! isUndefined( component.props.field.query ) ) {
				query = { ...query, ...component.props.field.query };
			}

			wpPosts.fetch({
				data : {
					per_page: 100,
					filter: query
				} }).done(function (models) {
					{Object.keys(models).map(function ( i ) {
						let model = models[i];

						let pre = '';
						let title = pre + model.title.rendered;

						if ( model.parent > 0 ) {
							pre = ' –– '
						}

						if ( isEmpty( model.title.rendered ) ) {
							title = pre + '<No title!>'
						}

						posts.push({ key: model.id, value: model.id.toString(), text: title });
					})}

					component.setState( { posts: posts, loading: false } );
				});
		});
	}
}

SocketPostSelect.propTypes = {
	name: PropTypes.string,
	value: PropTypes.array,
	setup_loading_flag: PropTypes.func
}

export default SocketPostSelect;
