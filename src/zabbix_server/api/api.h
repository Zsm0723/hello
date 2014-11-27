/*
** Zabbix
** Copyright (C) 2001-2014 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

#ifndef ZABBIX_API_H
#define ZABBIX_API_H

#include "db.h"

/* if set then filter matches if any item matches */
#define ZBX_API_FILTER_OPTION_ANY	1
/* if set then * wildcard is allowed for LIKE type filters */
#define ZBX_API_FILTER_OPTION_WILDCARD	2
/* if set then LIKE type filters drops starting % */
#define ZBX_API_FILTER_OPTION_START	4
/* if set then inverses LIKE filter */
#define ZBX_API_FILTER_OPTION_EXCLUDE	8


#define ZBX_API_PARAM_QUERY_EXTEND	"extend"
#define ZBX_API_PARAM_QUERY_COUNT	"count"

/* sort objects in ascending order */
#define ZBX_API_SORT_ASC		0
/* sort objects in descending order */
#define ZBX_API_SORT_DESC		1

#define ZBX_API_PARAM_NAME_SIZE		256

/* json result tags */
#define ZBX_API_RESULT_TAG_JSONRPC	"jsonrpc"
#define ZBX_API_RESULT_TAG_ID		"id"
#define ZBX_API_RESULT_TAG_RESULT	"result"
#define ZBX_API_RESULT_TAG_ERROR	"error"
#define ZBX_API_RESULT_TAG_ERRCODE	"code"
#define ZBX_API_RESULT_TAG_ERRMESSAGE	"message"
#define ZBX_API_RESULT_TAG_ERRDATA	"data"

/* api request data */
typedef struct
{
	zbx_uint64_t	id;
	int		type;
}
zbx_api_user_t;

/* object field definition */
typedef struct
{
	/* field name */
	char		*name;
	/* ZBX_TYPE_ */
	unsigned char	type;
	/* see ZBX_API_FIELD_FLAG_* defines */
	unsigned int	flags;
}
zbx_api_field_t;

/* filter definition */
typedef struct
{
	/* (field,value) pairs to be used for exact matching */
	zbx_vector_ptr_pair_t	exact;

	/* (field,value) paris to be used for 'like' matching */
	zbx_vector_ptr_pair_t	like;

	/* filter options, see ZBX_API_FILTER_OPTION_* defines */
	unsigned char	options;

}
zbx_api_filter_t;

/* query parameter definition */
typedef struct
{
	/* a vector of output fields, when empty query is interpreted as select count(*) */
	zbx_vector_ptr_t	fields;

	/* The number of fields specified by the request.                                */
	/* During processing fields required to fetch referred data might be added to    */
	/* fields vector, so fields_num should be used to determine the number of        */
	/* columns when creating response json.                                          */
	int			fields_num;

	/* Index of the key field in fields list vector.                                  */
	/* For main query the key field is the object id field if preservekeys is set or  */
	/* 0 otherwise.                                                                   */
	/* For sub queries the key field is the main query result field, used to execute  */
	/* sub queries.                                                                   */
	/* If they key_index is -1 then the query is not active.                          */
	int		key;
}
zbx_api_query_t;

/* output sorting definition */
typedef struct
{
	/* sort field */
	const zbx_api_field_t	*field;

	/* sorting order, see ZBX_API_SORT_* defines */
	unsigned char		order;
}
zbx_api_sort_t;

/* common get request options */
typedef struct
{
	/* the configured parameter mask */
	unsigned int		parameters;

	/* preservekeys */
	unsigned char		output_indexed;

	/* editable */
	unsigned char		filter_editable;

	/* limit */
	int			limit;

	/* filter, search, excludeSearch, searchByAny, searchWildcardsEnabled, startSearch */
	/* (a vector of zbx_api_filter_t structures)                                       */
	zbx_api_filter_t	filter;

	/* output, countOutput */
	zbx_api_query_t		output;

	/* the starting index of hidden fields that are retrieved from database, */
	/* but not returned with the rest of fields                              */
	int			output_num;

	/* sort, sortOrder (a vector of zbx_api_sort_t structures) */
	zbx_vector_ptr_t	sort;
}
zbx_api_getoptions_t;

/*
 * The result sets from database queries are usually stored as ptr vectors
 * of rows, each containing str vector of columns, as shown below:
 *
 *            .--------------------.
 *            |        rows        |
 *            | <zbx_vector_ptr_t> |
 *            |--------------------|
 *         .--| row1               |
 *      .--|--| row2               |
 *      |  |  | ...                |
 *   .--|--|--| rowN               |
 *   |  |  |  '--------------------'
 *   |  |  |
 *   |  |  |  .--------------------.
 *   |  |  '->|      columns       |
 *   |  |     | <zbx_vector_str_t> |
 *   |  |     |--------------------|
 *   |  |     | column1            |
 *   |  |     | column2            |
 *   |  |     | ...                |
 *   |  |     | columnK            |
 *   |  |     '--------------------'
 *   |  |
 *   |  |     .--------------------.
 *   |  '---->|      columns       |
 *   |        | <zbx_vector_str_t> |
 *   |        |--------------------|
 *   |        | column1            |
 *   |        | column2            |
 *   |        | ...                |
 *   |        | columnK            |
 *   |        '--------------------'
 *   |
 *   |                . . .
 *   |
 *   |        .--------------------.
 *   '------->|      columns       |
 *            | <zbx_vector_str_t> |
 *            |--------------------|
 *            | column1            |
 *            | column2            |
 *            | ...                |
 *            | columnK            |
 *            '--------------------'
 *
 * All values are stored as strings except nulls that have NULL value.
 *
 * The result set of the main request query is stored in zbx_api_get_result_t structure
 * rows vector. For example when executing mediatype.get request the rows vector will
 * contain the requested data from media_type table.
 *
 * If the request has sub queries (defined with select<Objects> request parameter) then
 * for every sub query a zbx_api_query_result_t structure is created and added to
 * zbx_api_get_result_t structure queries vector. This data basically is an additional
 * column to the main result set, with a result set per row.
 *
 * Then the sub queries are executed for each main result set (rows) row and the returned
 * result sets are stored corresponding zbx_api_query_result_t structure rows vector, matching
 * the main result set row by row.
 *
 *       .--------------------.
 *       |      queries       |
 *       | <zbx_vector_ptr_t> |
 *       |--------------------|
 *   .---| query1             |
 *   |   | ...                |
 *   | .-| queryQ             |
 *   | | '--------------------'
 *   | |
 *   | '------------------------------------------------.
 *   |                                                  |
 *   '-----------------.                                |
 *	               v                                v
 *       .--------------------------.     .--------------------------.
 *       |        columnK+1         |     |        columnK+Q         |
 *       | <zbx_api_query_result_t> |     | <zbx_api_query_result_t> |
 *       |--------------------------|     |--------------------------|
 *       | name                     |     | name                     |
 *       | query                    | ... | query                    |
 *       | rows[]                   |     | rows[]                   |
 *       |   resultset1             |     |   resultset1             |
 *       |   resultset2             |     |   resultset2             |
 *       |      ...                 |     |      ...                 |
 *       |   resultsetN             |     |   resultsetN             |
 *       '--------------------------'     '--------------------------'
 *
 * The resultsetX result sets stored in query rows matches the rows of the main result set:
 *   row1 -> resultset1
 *   row2 -> resultset2
 *      ...
 *   rowN -> resultsetN
 */


/* data returned by get request sub query (select<Object> parameter) */
typedef struct
{
	/* the sub query name */
	char			*name;

	/* the sub query */
	const zbx_api_query_t	*query;

	/* a vector of result sets matching main query rows by index */
	zbx_vector_ptr_t	rows;
}
zbx_api_query_result_t;

/* data retrieved by API get request */
typedef struct
{
	/* the retrieved rows containing columns specified by get request output option */
	zbx_vector_ptr_t	rows;

	/* A vector of sub query results (zbx_api_query_result_t).      */
	/* Those sub queries are specified by select<Object> parameters */
	/* And performed for each row retrieved by the main query.      */
	zbx_vector_ptr_t	queries;
}
zbx_api_get_result_t;

/* object field flags */
#define ZBX_API_FIELD_FLAG_SORTABLE	1
#define ZBX_API_FIELD_FLAG_REQUIRED	2
#define ZBX_API_FIELD_FLAG_CALCULATED	4



void	zbx_api_getoptions_init(zbx_api_getoptions_t *self);
int	zbx_api_getoptions_parse(zbx_api_getoptions_t *self, const zbx_api_field_t *fields, const char *parameter,
		struct zbx_json_parse *json, const char **next, char **error);

int	zbx_api_getoptions_finalize( zbx_api_getoptions_t *self, const zbx_api_field_t *fields, char **error);
int	zbx_api_getoptions_add_output_field(zbx_api_getoptions_t *self, const zbx_api_field_t *fields,
		const char *name, int *index, char **error);
void	zbx_api_getoptions_free(zbx_api_getoptions_t *self);

void	zbx_api_query_init(zbx_api_query_t *self);
void	zbx_api_query_free(zbx_api_query_t *self);

int	zbx_api_get_param_query(const char *param, const char **next, const zbx_api_field_t *fields,
		zbx_api_query_t *value, char **error);
int	zbx_api_get_param_flag(const char *param, const char **next, unsigned char *value, char **error);
int	zbx_api_get_param_bool(const char *param, const char **next, unsigned char *value, char **error);
int	zbx_api_get_param_int(const char *param, const char **next, int *value, char **error);
int	zbx_api_get_param_object(const char *param, const char **next, zbx_vector_ptr_pair_t *value, char **error);
int	zbx_api_get_param_string_or_array(const char *param, const char **next, zbx_vector_str_t *value, char **error);
int	zbx_api_get_param_idarray(const char *param, const char **next, zbx_vector_uint64_t *value, char **error);

void	zbx_api_sql_add_query(char **sql, size_t *sql_alloc, size_t *sql_offset, const zbx_api_query_t *query,
		const char *table, const char *alias);
void	zbx_api_sql_add_filter(char **sql, size_t *sql_alloc, size_t *sql_offset, const zbx_api_filter_t *filter,
		const char *alias, const char **sql_condition);
void	zbx_api_sql_add_sort(char **sql, size_t *sql_alloc, size_t *sql_offset, const zbx_vector_ptr_t *sort,
		const char *alias);

void	zbx_api_db_clean_rows(zbx_vector_ptr_t *rows);
void	zbx_api_db_free_rows(zbx_vector_ptr_t *rows);

int	zbx_api_db_fetch_rows(const char *sql, int fields_num, int rows_num, zbx_vector_ptr_t *resultset, char **error);
int	zbx_api_db_fetch_query(char **sql, size_t *sql_alloc, size_t *sql_offset, const char *column_name,
		const zbx_api_query_t *query, zbx_api_get_result_t *result, char **error);

void	zbx_api_get_result_init(zbx_api_get_result_t *self);
void	zbx_api_get_result_clean(zbx_api_get_result_t *self);


const zbx_api_field_t	*zbx_api_object_get_field(const zbx_api_field_t *fields, const char *name);

void	zbx_api_json_init(struct zbx_json *json, const char *id);
void	zbx_api_json_add_count(struct zbx_json *json, const char *name, const zbx_vector_ptr_t *rows);
void	zbx_api_json_add_result(struct zbx_json *json, zbx_api_getoptions_t *options, zbx_api_get_result_t *result);
void	zbx_api_json_add_row(struct zbx_json *json, const zbx_api_query_t *query, const zbx_vector_str_t *columns,
		const zbx_vector_ptr_t *queries, int row);
void	zbx_api_json_add_query(struct zbx_json *json, const char *name, const zbx_api_query_t *query,
		const zbx_vector_ptr_t *rows);
void	zbx_api_json_add_error(struct zbx_json *json, const char *error);

#endif
