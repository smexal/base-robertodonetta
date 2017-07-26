# API Documentation

### Basic Queries
**Basic Query for geting Images:**
```/get/images```

**All Available Tables are requestable like this:**
```/get/people```

**Expand to make sure, you get all fields.**
```/get/images?displayAll```

### Filters
You always have to add the three parameters field / value / type for a successfull query.
**Filter Example**
```get/images/filter?field=id&value=1&type=EQUALS&displayAll```
> You can only filter by the fields which are displayed without the "displayAll" attribute.

##### Avaible Filter Types
* ```EQUALS```
* ```LIKE``` (results in a LIKE '' Query)
* ```FUZZY``` (results in a LIKE %x% Query)

### Arguments
Other available Arguments which can be used:
* ```order``` (field which you want the results to be ordered)
* ```orderDir``` (order direction ASC [default] / DESC)
* ```limit``` LIMIT for SQL Query (default: 30) can be deactivated, by setting it to "none"
* ```offset``` OFFSET for SQL Query (default: 0)