import React from "react"
import ReactDOM from "react-dom"
import PropTypes from 'prop-types'
import {
	Dropdown,
	Form
} from 'semantic-ui-react'

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
			var query = {per_page: 100, taxonomy: 'categories'};

			if (!_.isUndefined(component.props.field.query)) {
				query = {...query, ...component.props.field.query};
			}

			if (_.isUndefined(query.taxonomy)) {
				return;
			}

			var rest_base = query.taxonomy;

			// check if this taxonomy has a different rest_base than the taxonomy name
			if ( ! _.isUndefined( socket.wp.taxonomies[rest_base] ) && ! _.isEmpty( socket.wp.taxonomies[rest_base].rest_base ) ) {
				rest_base = socket.wp.taxonomies[rest_base].rest_base;
			}

			var terms = [],
				url = socket.wp_rest.root + 'wp/v2/' + rest_base + '?per_page=' + query.per_page;

			fetch(url)
				.then((response) => {
					return response.json()
				})
				.then((results) => {
					{
						Object.keys(results).map(function (i) {
							var model = results[i];

							if (!_.isUndefined(model.id)) {
								var pre = '';

								if ( model.parent > 0 ) {
									pre = ' –– '
								}

								terms.push({key: model.id, value: model.id.toString(), text: pre + model.name});
							}
						})
					}

					component.setState({terms: terms, loading: false});
				});
		});
	}
}