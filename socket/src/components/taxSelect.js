import React from 'react'
import PropTypes from 'prop-types'
import {
	Dropdown,
	Form
} from 'semantic-ui-react'
import isEmpty from 'lodash/isEmpty'
import isUndefined from 'lodash/isUndefined'

class SocketTaxSelect extends React.Component {

	constructor (props) {
		// this makes the this
		super(props);

		// get the current state localized by wordpress
		this.state = {
			loading: false,
			terms: [],
			name: null,
			value: this.props.value
		};

		this.handleChange = this.handleChange.bind(this)
	}

	render () {
		let component = this,
			output = null,
			value = this.props.value,
			placeholder = this.props.placeholder || 'Select';

		if (isEmpty(value)) {
			value = []
		}

		output = <Form.Field className="post_type_select">
			<Dropdown
				placeholder={placeholder}
				fluid
				search
				selection
				multiple
				closeOnBlur
				closeOnEscape
				loading={this.state.loading}
				defaultValue={value}
				options={this.state.terms}
				onChange={component.handleChange}
			/>
		</Form.Field>;

		return output
	}

	handleChange = (e, {value}) => {
		let component = this;

		component.props.setupLoadingFlag(true);

		jQuery.ajax({
			url: socket.wp_rest.root + socket.wp_rest.api_base + '/option',
			method: 'POST',
			beforeSend: function (xhr) {
				xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce)
			},
			data: {
				'socket_nonce': socket.wp_rest.socket_nonce,
				name: this.props.name,
				value: value
			}
		}).done(function (response) {
			component.props.setupLoadingFlag(false)
		}).error(function (err) {
			console.log(err);
			component.props.setupLoadingFlag(false)
		});

		this.setState({value})
	};

	componentWillMount () {
		if (this.state.loading) {
			return false
		}

		let component = this;

		wp.api.loadPromise.done(async function () {
			let terms = [],
				query = {};

			if (!isUndefined(component.props.field.query)) {
				query = {...query, ...component.props.field.query}
			}

			if (isUndefined(query.taxonomy)) {
				console.error( 'No taxonomy specified for tax query!' );
				return
			}

			let rest_namespace = 'wp/v2';
			let rest_base = query.taxonomy;

			// check if this taxonomy has a different rest_namespace than the default one
			if (!isUndefined(socket.wp.taxonomies[query.taxonomy]) && !isEmpty(socket.wp.taxonomies[query.taxonomy].rest_namespace)) {
				rest_namespace = socket.wp.taxonomies[query.taxonomy].rest_namespace
			}

			// check if this taxonomy has a different rest_base than the taxonomy name
			if (!isUndefined(socket.wp.taxonomies[query.taxonomy]) && !isEmpty(socket.wp.taxonomies[query.taxonomy].rest_base)) {
				rest_base = socket.wp.taxonomies[query.taxonomy].rest_base
			}

			let Collection = wp.api.getCollectionByRoute( '/' + rest_namespace + '/' + rest_base );
			if ( typeof Collection === 'undefined' ) {
				console.error( 'Could not find a WP API collection for route: ' + '/' + rest_namespace + '/' + rest_base );
				return;
			}

			component.setState({loading: true});

			await( new Collection() ).fetch( {
				data: {
					source: "socket",
					per_page: 100,
				}
			} ).done(function (models) {
				{
					Object.keys(models).map(function (i) {
						let model = models[i];

						if (!isUndefined(model.id)) {
							let pre = '';

							if (model.parent > 0) {
								pre = ' –– '
							}

							terms.push({key: model.id, value: model.id.toString(), text: pre + model.name})
						}
					})
				}

				component.setState({terms: terms, loading: false})
			})
		})
	}
}

SocketTaxSelect.propTypes = {
	name: PropTypes.string,
	value: PropTypes.array,
	setupLoadingFlag: PropTypes.func
};

export default SocketTaxSelect
