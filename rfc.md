# Query Database Timeouts

* Creator: [Shmuel, Jake]
* Relevant Branch:  https://github.com/utopia-php/database/pull/220

## Summary
We want to give Appwrite's users, who use queries api flexibility options for making any query they desire, with no limitations.
Today they are restricted to have indexes for specific queries conditions.
The problem begin with collections with a relative big amount of data inside, which can make queries with no indexes or `bad` queries in sense of sql with operators as `not in` or `<>`

## Resources
Google, Mysql workbench tool, Mongosh.

## Implementation
We will limit specific `select` type queries executions or set by session a timeout for all select queries during the connection session, for a specific api call, a timeout in milliseconds.
In case the select operation exceeds time limit, an Exception will be thrown with an error code value per adapter (mysql, mongo..etc) that we will catch.
We will audit the error throw with data from the api , such as the queries variable, user making the query, host.
After a number of times wee will block this call.
We will have to show on the console this lists of audit , so the user can try to fix the queries , or perhaps we can add a recommendation of adding a specific index.
<!-- Write an overview to explain the suggested implementation -->

### API Changes
If case we want to limit time execution for a whole api call we can set by session the execution time limit for all select queries in that connection thread.
In case mongo will throw a 'Utopia\Mongo\Exception' with Error Code 50, with a message such as "E50 MaxTimeMSExpired:operation exceeded time limit".

<!-- Do we need new API endpoints? List and describe them and their API signatures -->

###  Workers / Commands
Perhaps a worker sending the index he has a problem on a specific end point?

<!-- Do we need new workers or commands for this feature? List and describe them and their API signatures -->

###  Supporting Libraries
Utopia abuse , Utopia audit, Utopia database, Utopia mongo.
<!-- Do we need new libraries for this feature? Mention which, define the file structure, and different interfaces -->

### Data Structures
No change
<!-- Do we need new data structures for this feature? Describe and explain the new collections and attributes -->

### Breaking Changes
In case we use the injection sql timeouts a change to sql syntax will be changed.
No problem at all with backward compatability at all.
<!-- Will this feature introduce any breaking changes? How can we achieve backward compatability -->

### Documentation & Content
We need to add relevant docs how to index queries better and what are the user's options to remove the blocked api calls.
<!-- What documentation do we need to update or add for this feature? -->

## Reliability

### Security
All changes are internal , so no major security changes here.
<!-- How will we secure this feature? -->

### Scaling
Not relevant
<!-- How will we scale this feature? -->

### Benchmark
<!-- How will we benchmark this feature? -->

### Tests (UI, Unit, E2E)
We need to add tests with the ability to mock exceed timeout queries, for catching the exceptions. 
currently, mocks are hardcoded, except mongo db, where I was able to add sleep condition in queries.
It is hard to inject sleep method to where conditions or select part of the sql.

<!-- How will we test this feature? -->