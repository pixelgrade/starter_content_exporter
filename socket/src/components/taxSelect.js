import React from "react"
import PropTypes from 'prop-types'
import {
	Dropdown,
	Form
} from 'semantic-ui-react'
import _ from 'lodash'

export default class SocketTaxSelect extends React.Component {
	static propTypes = {
		name: PropTypes.string,
		value: PropTypes.array,
		setup_loading_flag: PropTypes.func
	}

	constructor(props) {
		// this makes the this
		super(props);

		// get the current state localized by wordpress
		this.state = {
			loading: true,
			terms: [],
			name: null,
			value: this.props.value,
			value_on_open: null
		};

		this.handleClose = this.handleClose.bind(this);
	}

	render() {
		var component = this,
			output = null,
			value = this.props.value,
			placeholder = this.props.placeholder || 'Select';

		if ( _.isEmpty( value ) ) {
			value = []
		}

		output = <Form.Field className="post_type_select" >
			<Dropdown
				placeholder={placeholder}
				search
				selection
				closeOnBlur={false}
				multiple={true}
				loading={this.state.loading}
				defaultValue={value}
				options={this.state.terms}
				onChange={component.handleChange}
				onClose={component.handleClose}
				onOpen={component.handleOpen}
			/>
		</Form.Field>

		return output;
	}

	handleChange = (e, { value }) => {
		this.setState({ value });
	}

	handleOpen = (e) => {
		this.state.value_on_open = this.state.value;
	}

	// on close we want to save the data
	handleClose(e){
		let component = this,
			value = this.state.value

		if ( value === component.state.value_on_open ) {
			return;
		}

		component.props.setup_loading_flag( true )

		jQuery.ajax({
			url: socket.wp_rest.root + socket.wp_rest.api_base +  '/option',
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
			// let new_values = component.state.values;
			console.log(response);
			component.props.setup_loading_flag( false );
		}).error(function (err) {
			console.log(err);
			component.props.setup_loading_flag( false );
		});
	}

	componentWillMount(){
		if ( ! this.state.loading ) {
			return false;
		}

		let component = this

		wp.api.loadPromise.done( function() {

			if (!_.isUndefined(component.props.field.query.taxonomy) ) {
				var collection = component.props.field.query.taxonomy;

				// some taxonomies have special collections.
				if ( collection === 'post_category' || collection === 'category' ) {
					collection = 'Categories';
				}
				if ( collection === 'post_tag' ) {
					collection = 'Tags';
				}

				collection = wp.api.utils.capitalizeAndCamelCaseDashes( collection );

				if ( !_.isUndefined( wp.api.collections[collection] ) ) {
					var wpTaxonomy = new wp.api.collections[collection];

					var terms = [];

					wpTaxonomy.fetch().done(function (models) {
						{
							Object.keys(models).map(function (i) {
								let model = models[i];

								if (!_.isUndefined(model.id)) {
									var pre = '';

									if (model.parent > 0) {
										pre = ' –– '
									}

									terms.push({key: model.id, value: model.id.toString(), text: pre + model.name});
								}
							})
						}

						component.setState({terms: terms, loading: false});
					});
				}
			}
		});
	}
}
