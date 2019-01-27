---
title:  "Meanwhile... on the query side of my architecture"
date:   2011-12-18
tags:   [.NET General, Architecture, C#, Dependency Injection]
draft:  false
aliases:
    - /p/queries
---

### Command-query separation is a common concept in the software industry. Many architectures separate commands from the rest of the system and send command messages that are processed by command handlers. This same concept of messages and handlers can just as easily be applied to the query side of an architecture. There are not many systems using this technique and this article is an attempt to change that. Two simple interfaces will change the look of your architecture... forever.

In [my previous post](/blogs/steven/p/commands/) I described how I design the command side of my systems. The greatest thing about this design is that it provides a lot of flexibility and lowers the overall complexity of the system through the addition of one simple interface to the system. The design is founded on the [SOLID principles](https://en.wikipedia.org/wiki/SOLID) and is brought to life with Dependency Injection (although DI is optional). Please read that post if you haven’t already, as this post will regularly refer to its content.

It’s funny that I encountered the command/handler design so many years ago but failed to understand why anyone would use two classes for one operation (one for the data and one for the behavior). It didn’t seem very object oriented to me and it was only after I experienced problems with the old design (message and behavior in the same class) that the potential of the command/handler design became clear to me.

With my business layer modeled uniformly and giving me great flexibility it then became clear that the same wasn't true for the part of the business layer that was responsible for querying. It was this dissatisfaction that triggered me to think about the design of this part of my application architecture.

Originally I would model queries as methods with clear names and group them together in a class. This led to interfaces like the following:

{{< highlight csharp >}}
public interface IUserQueries
{
    User[] FindUsersBySearchText(string searchText, bool includeInactiveUsers);
    User[] GetUsersByRoles(string[] roles);
    UserInfo[] GetHighUsageUsers(int reqsPerDayThreshold);

    // More methods here
}
{{< / highlight >}}

There is a variation of this pattern that a lot of developers use today in their applications. They mix this query class with the [repository pattern](https://martinfowler.com/eaaCatalog/repository.html). The repository pattern is used for [CRUD](https://en.wikipedia.org/wiki/Create,_read,_update_and_delete) operations. The following code may look familiar:


{{< highlight csharp >}}
// Generic repository class (okay)
public interface IRepository<T>
{
    T GetByKey(int key);
    void Save(T instance);
    void Delete(T instance);
}

// Custom entity-specific repository with query methods (awkward)
public interface IUserRepository : IRepository<User>
{
    User[] FindUsersBySearchText(
        string searchText, bool includeInactiveUsers);
    User[] GetUsersByRoles(string[] roles);
    UserInfo[] GetHighUsageUsers(int reqsPerDayThreshold);

    // More methods here
}
{{< / highlight >}}

Alongside the `IUserQueries` interface, my application contained interfaces such as        `IPatientInfoQueries`, `ISurgeryQueries`, and countless others, each with its own set of methods and its own set of parameters and return types. Every interface was different, which made adding [cross-cutting concerns](https://en.wikipedia.org/wiki/Cross-cutting_concern), such as logging, caching, profiling, security, etc very difficult. I was missing the uniformity in the design that I had with my command handlers. The query classes were just a bunch of random methods, often grouped around one concept or one entity. No matter how hard I tried, it would end up looking messy and each time a query method was added both the interface and the implementation would need to be changed.

In my automated test suite things were even worse! A class under test that depended on a query interface was often only expected to call one or two of its methods, while other classes were expected to call other methods. This meant I had to do hundreds of asserts in my test suite to ensure a class didn’t call unexpected methods. This resulted in the creation of abstract base classes in my test project that implemented one of these query interfaces. Each abstract class looked something like this:

{{< highlight csharp >}}
public abstract class FakeFailingUserQueries : IUserQueries
{
    public virtual User[] FindUsersBySearchText(
        string searchText, bool includeInactive)
    {
        Assert.Fail("Call to this method was not expected.");
        return null;
    }
 
    public virtual User[] GetUsersByRoles(string[] roles)
    {
        Assert.Fail("Call to this method was not expected.");
        return null;
    }
        
    public virtual UserInfo[] GetHighUsageUsers(
        int requestsPerDayThreshold)
    {
        Assert.Fail("Call to this method was not expected.");
        return null;
    }

    // More methods here
}
{{< / highlight >}}

For each test I would inherit from this base class and override the relevant the method:


{{< highlight csharp >}}
public class FakeUserServicesUserQueries : FakeFailingUserQueries
{
    public User[] UsersToReturn { get; set; }
    public string[] CalledRoles { get; private set; }
 
    public override User[] GetUsersByRoles(string[] roles)
    {
        this.CalledRoles = roles;
        return this.UsersToReturn;
    }
}
{{< / highlight >}}

All this meant I could leave all the other methods fail (since they were not expected to be called) which saved me from having to write too much code and reduced the chance of errors in my tests. But it still led to an explosion of test classes in my test projects.

Of course all these problems can be solved with the ‘proper’ tooling. For instance, cross-cutting concerns can be added by using compile-time code weaving (using [PostSharp](https://www.postsharp.net/) for instance), or by configuring your DI container using convention based registration, mixed with interception (interception uses dynamic proxy generation and lightweight code generation). The testing problems can be resolved by using Mocking frameworks (which also generate proxy classes that act like the original class).

These solutions work, but they usually only make things more complicated and in reality they are patches to hide problems with the initial design. When we validate the design against the five SOLID principles, we can see where the problem lies. The design violates three of the five SOLID principles:

* The [Single Responsibility Principle](https://en.wikipedia.org/wiki/Single_responsibility_principle) is violated, because the methods in each class are not highly cohesive. The only thing that relates those methods is the fact that they belong to the same concept or entity.
* The design violates the [Open/Closed Principle](https://en.wikipedia.org/wiki/Open/closed_principle), because almost every time a query is added to the system, an existing interface and its implementations need to be changed. Every interface has at least two implementations: one real implementation and one test implementation.
* The [Interface Segregation Principle](https://en.wikipedia.org/wiki/Interface_segregation_principle) is violated, because the interfaces are wide (have many methods) and consumers of those interfaces are forced to depend on methods that they don’t use. 

So let us not treat the symptoms; let’s address the cause.

## A better design

Instead of having a separate interface per group of queries, we can define a single interface for all the queries in the system, just as we saw with the ICommandHandler<TCommand> interface in my previous article. We define the following two interfaces:

{{< highlight csharp >}}
public interface IQuery<TResult> { }
 
public interface IQueryHandler<TQuery, TResult>
    where TQuery : IQuery<TResult>
{
    TResult Handle(TQuery query);
}
{{< / highlight >}}

The use of such  `IQuery<TResult>` interface is something that [Udi Dahan](http://www.udidahan.com/) [mentioned back in 2007](http://www.udidahan.com/2007/03/28/query-objects-vs-methods-on-a-repository/). So in fact this concept isn't new at all. Unfortunately, Udi doesn't go into much details in his article.

The `IQuery<TResult>` specifies a query message with `TResult` as the query's return type. Although this interface doesn't look very useful, I will explain later on why having such an interface is crucial.

Whereas a command is normally "fire and forget" and will update something in the system and not return a value, a query is the opposite, in that it will not change any state and does return a value.

Using the previously defined interface we can define a query message:

{{< highlight csharp >}}
public class FindUsersBySearchTextQuery : IQuery<User[]>
{
    public string SearchText { get; set; }
    public bool IncludeInactiveUsers { get; set; }
}
{{< / highlight >}}

This class defines a query operation with two parameters that returns an array of User objects. Just like a command, this query message class is a [Parameter Object](https://refactoring.com/catalog/introduceParameterObject.html).

![Parameter Object Refactoring Cue Card](/blogs/steven/images/smells-refactoring-cards-sample.jpg)

The class that handles this message can be defined as follows:

{{< highlight csharp >}}
public class FindUsersBySearchTextQueryHandler
    : IQueryHandler<FindUsersBySearchTextQuery, User[]>
{
    private readonly NorthwindUnitOfWork db;
 
    public FindUsersBySearchTextQueryHandler(NorthwindUnitOfWork db)
    {
        this.db = db;
    }
 
    public User[] Handle(FindUsersBySearchTextQuery query)
    {
        return (
            from user in this.db.Users
            where user.Name.Contains(query.SearchText)
            select user)
            .ToArray();
    }
}
{{< / highlight >}}

As we’ve seen with the command handlers, we can now let consumers depend on the generic `IQueryHandler` interface:

{{< highlight csharp >}}
public class UserController : Controller
{
    IQueryHandler<FindUsersBySearchTextQuery, User[]> handler;
 
    public UserController(
        IQueryHandler<FindUsersBySearchTextQuery, User[]> hndler)
    {
        this.handler = handler;
    }
 
    public View SearchUsers(string searchString)
    {
        var query = new FindUsersBySearchTextQuery
        {
            SearchText = searchString,
            IncludeInactiveUsers = false
        };
 
        User[] users = this.handler.Handle(query);

        return this.View(users);
    }
}
{{< / highlight >}}

This model gives us a lot of flexibility because we can now decide what to inject into the `UserController`. As we’ve seen in the previous article, we can inject a completely different implementation, or one that wraps the real implementation, without having to make any changes to the `UserController` (and all other consumers of that interface).

Note this is where the `IQuery<TResult>` interface comes into play. It prevents us from having to cast the return type (to `User[]` in this example); it therefore gives us compile-time support when working with the query handler; it provides compile-time support when specifying or injecting `IQueryHandler`s into our code. If we were to change the `FindUsersBySearchTextQuery` to return `UserInfo[]` instead of `User[]` (by updating it to implement `IQuery<UserInfo[]>`), the `UserController` would fail to compile because the generic type constraint on `IQueryHandler<TQuery, TResult>` won't be able to map `FindUsersBySearchTextQuery` to User[].

Injecting the `IQueryHandler` interface into a consumer however, has some less obvious problems that still need to be addressed.

The number of dependencies if our consumers might get too big and can lead to [constructor over-injection](https://blog.ploeh.dk/2010/01/20/RebuttalConstructorover-injectionanti-pattern/)—when a constructor takes too many arguments (a common rule of thumb is that a constructor should take no more than 4 or 5 arguments). Constructor over-injection is an anti-pattern and is often a sign that the [Single Responsibility Principle](https://en.wikipedia.org/wiki/Single_responsibility_principle) (SRP) has been violated. Although it is important to adhere to SRP, it is also highly likely that consumers will want to execute multiple different queries without really violating SRP (in contrast to injecting many `ICommandHandler<TCommand>` implementations which would certainly be a violation of SRP). In my experience the number of queries a class executes can change frequently, which would require constant changes into the number of constructor arguments.

Another shortcoming of this approach is that the generic structure of the `IQueryHandler<TQuery, TResult>` leads to lots of infrastructural code which in turn makes the code harder to read. For example:

{{< highlight csharp >}}
public class Consumer
{
    IQueryHandler<FindUsersBySearchTextQuery, IQueryable<UserInfo>> findUsers;
    IQueryHandler<GetUsersByRolesQuery, IEnumerable<User>> getUsers;
    IQueryHandler<GetHighUsageUsersQuery, IEnumerable<UserInfo>> getHighUsage;
 
    public Consumer(
        IQueryHandler<FindUsersBySearchTextQuery, IQueryable<UserInfo>> findUsers,
        IQueryHandler<GetUsersByRolesQuery, IEnumerable<User>> getUsers,
        IQueryHandler<GetHighUsageUsersQuery, IEnumerable<UserInfo>> getHighUsage)
    {
        this.findUsers = findUsers;
        this.getUsers = getUsers;
        this.getHighUsage = getHighUsage;
    }
}
{{< / highlight >}}

Wow!! That’s a lot of code for a class that only has three different queries that it needs to execute. This is in part due to the verbosity of the C# language. A workaround (besides switching to another language) would be to use a T4 template that generates the constructor in a new partial class. This would leave us with just the three lines defining the private fields. The generic typing would still be a bit verbose, but with C# there's nothing much we can do about that.

So how do we fix the problem of having to inject too many `IQueryHandler`s? As always, with an extra layer of indirection :-). We create a Mediator that sits between the consumers and the query handlers:

{{< highlight csharp >}}
public interface IQueryProcessor
{
    TResult Process<TResult>(IQuery<TResult> query);
}
{{< / highlight >}}

The `IQueryProcessor` is a non-generic interface with one generic method. As you can see in the interface definition, the `IQueryProcessor` depends on the `IQuery<TResult>` interface. This allows us to have compile time support in our consumers that depend on the `IQueryProcessor`. Let’s rewrite the `UserController` to use the new `IQueryProcessor`:

{{< highlight csharp >}}
public class UserController : Controller
{
    private IQueryProcessor queries;
 
    public UserController(IQueryProcessor queries)
    {
        this.queries = queries;
    }
 
    public ActionResult SearchUsers(string searchString)
    {
        var query = new FindUsersBySearchTextQuery
        {
            SearchText = searchString
        };
 
        // Note how we omit the generic type argument,
        // but still have type safety.
        User[] users = this.queries.Process(query);

        return this.View(users);
    }
}
{{< / highlight >}}

The `UserController` now depends on a `IQueryProcessor` that can handle all of our queries. The `UserController`’s `SearchUsers` method calls the `IQueryProcessor.Process` method passing in an initialized query object. Since the `FindUsersBySearchTextQuery` implements the `IQuery<User[]>` interface, we can pass it to the generic `Execute<TResult>(IQuery<TResult> query)` method. Thanks to [C# type inference](https://msdn.microsoft.com/en-us/library/twcad0zb%28v=vs.80%29.aspx), the compiler is able to determine the generic type and this saves us having to explicitly state the type. The return type of the Process method is also known. So if we were to change the `FindUsersBySearchTextQuery` to implement a different interface (say `IQuery<IQueryable<User>>`) the `UserController` will no longer compile instead of miserably failing at runtime.

It is now the responsibility of the implementation of the `IQueryProcessor` to find the right `IQueryHandler`. This requires some dynamic typing, and optionally the use of a Dependency Injection library, and can all be done with just a few lines of code:

{{< highlight csharp >}}
sealed class QueryProcessor : IQueryProcessor
{
    private readonly Container container;

    public QueryProcessor(Container container)
    {
        this.container = container;
    }

    [DebuggerStepThrough]
    public TResult Process<TResult>(IQuery<TResult> query)
    {
        var handlerType = typeof(IQueryHandler<,>)
            .MakeGenericType(query.GetType(), typeof(TResult));

        dynamic handler = container.GetInstance(handlerType);

        return handler.Handle((dynamic)query);
    }
}
{{< / highlight >}}

he `QueryProcessor` class constructs a specific `IQueryHandler<TQuery, TResult>` type based on the type of the supplied query instance. This type is used to ask the supplied container class to get an instance of that type. Unfortunately we need to call the `Handle` method using reflection (by using the C# 4.0 `dymamic` keyword in this case), because at this point it is impossible to cast the handler instance, since the generic `TQuery` argument is not available at compile time. However, unless the `Handle` method is renamed or gets other arguments, this call will never fail and if you want to, it is very easy to write a unit test for this class. Using reflection will give a slight drop, but is nothing to really worry about (especially when you're using [Simple Injector](https://simpleinjector.org) as your DI library, because it is [blazingly fast](http://www.palmmedia.de/Blog/2011/8/30/ioc-container-benchmark-performance-comparison)).

I did consider an alternative design of the `IQueryProcessor` interface, that looked like this:

{{< highlight csharp >}}
public interface IQueryProcessor
{
    TResult Process<TQuery, TResult>(TQuery query)
        where TQuery : IQuery<TResult>;
}
{{< / highlight >}}

This version of the interface solves the problem of having to do dynamic typing in the `QueryProcessor` implementation completely, but sadly the C# compiler isn’t ‘smart’ enough to find out which types are needed ([damn you Anders](https://blogs.msdn.com/b/ericlippert/archive/2009/12/10/constraints-are-not-part-of-the-signature.aspx)!), which forces us to completely write out the call to `Process`, including both generic arguments. This gets really ugly in the code and is therefore not advisable. I was a bit amazed by this, because I was under the assumption that the C# compiler could infer the types. (However, the more I think about it, the more it makes sense that the C# compiler doesn't try to do so.)

There’s one very important point to note when using the `IQueryProcessor` abstraction. By injecting an `IQueryProcessor`, it is no longer clear which queries a consumer is using. This makes unit testing more fragile, since the constructor no longer tells us what services the class depends on. We also make it harder for our DI library to verify the object graph it is creating, since the creation of an `IQueryHandler` implementation is postponed by the `IQueryProcessor`. Being able to verify the container's configuration [is very important](https://simpleinjector.org/howto#verify-configuration). Using the `IQueryProcessor` means we have to write a test that confirms there is a corresponding query handler for every query in the system, because the DI library can not check this for you. I personally can live with that in the applications I work on, but I wouldn’t use such an abstraction too often. I certainly wouldn’t advocate an `ICommandProcessor` for executing commands - consumers are less likely to take a dependency on many command handlers and if they do it would probably be a violation of SRP.

#### **One word of advice:** When you start using this design, start out without the `IQueryProcessor` abstraction because of the reasons I described. It can always be added later on without any problem.

A consequence of the design based on the `IQueryHandler` interface is that there will be a lot of small classes in the system. And believe it or not, but having a lot of small / focused classes (with clear names) is actually a good thing. Many developers are afraid of having too many classes in the system, because they are used in working in big tangled code bases with lack of structure and the proper abstractions. The cause of what they are experiencing however isn't caused by the amount of classes, but by the lack of structure. Stop writing less code; start writing more maintainable code!

#### Although there are many ways to structure a project, I found it very useful to place each query class, its DTOs, and the corresponding handler in the same folder, which is named after the query. So the BusinessLayer/Queries/GetUsersByRoles folder might contain the files GetUserByRolesQuery.cs, UserByRoleResult.cs and GetUsersByRolesQueryHandler.cs.

Another fear of developers is long build times. Keeping the build times low is in my experience crucial for good developer productivity. The number of classes in a project however, hardly influences the build time. The number of projects on the other hand does. You'll often see that the build time increases exponentially with the number of projects in a solution. Reducing the number of classes wont help you a bit.

When using a DI library, we can normally register all query handlers with a single call (depending on the library), because all the handlers implement the same `IQueryHandler<TQuery, TResult>` interface. Your mileage may vary, but with Simple Injector, the registration looks like this:

{{< highlight csharp >}}
container.Register(
    typeof(IQueryHandler<,>),
    typeof(IQueryHandler<,>).Assembly);
{{< / highlight >}}

This code saves us from having to change the DI configuration any time we add a new query handler to the system. They will all be picked up automatically.

With this design in place we can add cross-cutting concerns such as logging, audit trail, etc. Or let’s say you want to decorate properties of the query objects with [Data Annotations](https://www.asp.net/mvc/tutorials/older-versions/models-%28data%29/validation-with-the-data-annotation-validators-cs) attributes, to enable validation:

{{< highlight csharp >}}
public class FindUsersBySearchTextQuery : IQuery<User[]>
{
    // Required and StringLength are attributes from the
    // System.ComponentModel.DataAnnotations assembly.
    [Required]
    [StringLength(1)]
    public string SearchText { get; set; }
 
    public bool IncludeInactiveUsers { get; set; }
}
{{< / highlight >}}

Because we modeled our query handlers around a single `IQueryHandler<TQuery, TResult>` interface, we can define a simple decorator that validates all query messages before they are passed to their handlers:

{{< highlight csharp >}}
public class ValidationQueryHandlerDecorator<TQuery, TResult>
    : IQueryHandler<TQuery, TResult>
    where TQuery : IQuery<TResult>
{
    private readonly IQueryHandler<TQuery, TResult> decorated;
 
    public ValidationQueryHandlerDecorator(
        IQueryHandler<TQuery, TResult> decorated)
    {
        this.decorated = decorated;
    }
 
    public TResult Handle(TQuery query)
    {
        Validator.ValidateObject(
            query,
            new ValidationContext(query, null, null),
            validateAllProperties: true);

        return this.decorated.Handle(query);
    }
}
{{< / highlight >}}

All without changing any of the existing code in our system beyond adding the following new line of code in the [Composition Root](https://freecontent.manning.com/dependency-injection-in-net-2nd-edition-understanding-the-composition-root/):

{{< highlight csharp >}}
container.RegisterDecorator(
    typeof(IQueryHandler<,>),
    typeof(ValidationQueryHandlerDecorator<,>));
{{< / highlight >}}

If you're concerned about performance and worry that this would add too much overhead by wrapping query handlers that don't need validation with a decorator, a DI container such as Simple Injector allows you to easily configure a conditional decorator:

{{< highlight csharp >}}
container.RegisterDecorator(
    typeof(IQueryHandler<,>),
    typeof(ValidationQueryHandlerDecorator<,>),
    context => ShouldQueryHandlerBeValidated(context.ServiceType));
{{< / highlight >}}

The applied predicate is evaluated just once per closed generic `IQueryHandler<TQuery, TResult>` type, so there is no performance loss in registering such a conditional decorator (or at least, with Simple Injector there isn't). As I said, your mileage may vary when using other DI libraries.

I’ve been using this model for some time now but there is one thing that struck me early on—everything in the system is either a query or a command and if we want, we can model every single operation in this way. But do we really want to? No, definitely not, mostly because it doesn’t always result in the most readable code. Take a look at this example:

{{< highlight csharp >}}
private IQueryHandler<GetCurrentUserIdQuery, int> userHandler;
private IQueryHandler<GetCurrentTimeQuery, DateTime> timeHandler;

public IQueryable<Order> Handle(GetRecentOrdersForLoggedInUserQuery query)
{
    int currentUserId = 
        this.userHandler.Handle(new GetCurrentUserIdQuery());
 
    DateTime currentTime =
        this.timeHandler.Handle(new GetCurrentTimeQuery());
 
    return
        from order in db.Orders
        where order.User.Id == currentUserId
        where order.CreateDate >= currentTime.AddDays(-30)
        select order;
}
{{< / highlight >}}

This query method is composed of other queries. Composing queries out of other queries is a great way to improve modularity and manage the complexity of the system. But still there is something smelly about this code. Personally, I find the following example easier to read:

{{< highlight csharp >}}
private IUserContext userContext;
private ITimeProvider timeProvider;

public IQueryable<Order> Handle(GetRecentOrdersForLoggedInUserQuery query)
{
    return
        from order in db.Orders
        where order.User.Id == this.userContext.UserId
        where order.CreateDate >= this.timeProvider.Now.AddDays(-30)
        select order;
}
{{< / highlight >}}

The previous sub queries are in this version replaced with the `IUserContext` and `ITimeProvider` services. Because of this, the method is now more concise and compact.

So where do we draw the line between using an `IQuery<TResult>` and specifying an explicit separate service interface? I can’t really define any specific rules on that; a little bit of intuition and experience will have to guide you. But to give a little bit of guidance, when a query returns a (cached) value without really hitting an external resource, such as the file system, web service, or database, and it doesn’t contain any parameters, and you’re pretty sure you never want to wrap it with a decorator (no performance measuring, no audit trailing, no authorization) it’s pretty safe to define it as a specific service interface. Another way to view this is to use this design to define business questions: things the business wants to know. In other words, use the `IQueryHandler<TQuery, TResult>` and `ICommandHandler<TCommand>` abstractions as the communication layer between the business layer and the layers above.This comes down to the idea of [holistic abstractions](http://scrapbook.qujck.com/holistic-abstractions-take-2/).

That’s how I roll on the query side of my architecture.

## Comments

---
#### Marco - 20 January 12
that is darn right scary how similar it is to our query architecture at my company! :-)

one thing i implemented was a cachequery attribute that we used for caching our query results.

---
#### Amiry - 14 September 12
Hi. Thanks for nice article. Actually my English is not good, so I'm not sure I understand or not. Do you mean, we should use `Command` to `update`, `delete` and `create`, and use `Query` to `select`?

---
#### Steven - 15 September 12
Hi Amiry, these patterns are especially useful in systems with business logic that’s more complex than simple CRUD operations. When looking closely at the requirements of a system, you will often find that a user's button click must do much more than just saving a single row in the database. Often multiple tables have to be updated, mails have to be constructed and sent, calculations have to be done, external systems have to be called, etc. etc. In that case it gets really useful to pack all business logic that happens after the 'button click' inside a command handler. If your system (or parts of the system), only consists of doing CRUD operations, you'd probably be better off by letting the presentation layer directly use repositories instead. Repositories could as well be decorated with cross-cutting concerns such as authorization, validation, logging, audit trailing, etc.

---
#### Amiry - 15 September 12
Yes I understand now. Thanks to comment. But another question, if we need the command returns a result, what can we do? For example, login-command should return a boolean(e.g. login: yes / no). How can we achieve this?

---
#### Steven - 15 September 12
If you need to return data from commands, take a look at [this article](/blogs/p/data-commands). However, in the case of logging in a user, I don't think that's really suited for a command, since you are really asking a question here (while doing a side effect). Instead, I would use an `IAuthorizationService` or something like that.

---
#### Daniel Hilgarth - 11 June 13
Steven, thanks for this series. I have a question about testing.
With this approach, it is very easy to test that the BL uses a certain query / command.
But how do you test the query / command handler itself? I only see a way via integration tests but not via unit tests. Do you agree?

---
#### Steven - 11 June 13
Hi Daniel, this series focuses on using the right abstractions and not so much on a particular handler implementation, although a simple example is given. For what it's worth, the article could have used a handler implementation with an embedded SQL statement.

The given handler example uses a unit of work that exposes an `IQueryable<T>`. `IQueryable<T>` is a leaky abstraction and this makes unit testing hard. I've written [in the past](/blogs/steven/pivot/entry.php?id=84) about a solution but the fact remains that `IQueryable<T>` is a leaky abstraction and is hard to test.

But using an `IQueryHandler<TQuery, TResult>` or `ICommandHandler<TCommand>` abstraction in itself does not limit the testability of the handler implementation.

---
#### Sam - 13 November 13
Hi Steven, great article. I've been looking for something like this for a long while now. Do you still use this approach or have you moved on to something even better now?

One problem I have with this approach is...if I was to have 2 layers i.e. "Application.Data" and "Application.Web" are they not tightly coupled because of "FindUsersBySearchTextQuery"?

---
### Steven - 13 November 13
Hi Sam,

I use this pattern on a daily basis on the projects I participate in. It brings me much joy, great power, and extreme flexibility. I can't imagine creating any system without this pattern.

Your layers will not be tightly coupled because of the use of this pattern, on the contrary. If two layers communicate with each other, they will have to send data. They simply need to have some communication contract; they must agree on what messages to send and accept. You can't build a system without passing data from one layer to the other. The communication contract is the absolute minimum amount of coupling you need between layers. `FindUsersBySearchTextQuery` is a message; it’s part of your *data contract*. On top of that data contract, the pattern defines just a single abstraction that describes how to communicate. This is the `IQueryHandler<TQuery, TResult>` interface. So the coupling is actually very low. I think it’s even safe to say that you can’t get any coupling that is lower than this.

Also note that although using `DataTable`s as query messages and return types lowers the number of runtime types that you send between layers, it *does not make the communication contract any smaller*—each sent and recieved `DataTable` is still expected to have a unique structure; each still has its own unique signature. Both the sender and receiver depend on this structure. When using the query/handler pattern, you make this contract *explicit* and add compile-time support to this contract.

---
#### Daniel Hilgarth - 13 November 13
I can only second that. Since using that pattern, the architecture of my applications has increased a lot and my mental load when writing them has decreased.

Why? Because you simply need to take a dependency on the `IQueryHandler<T, R>` interface and define the parameters of the query in a Query class. That's it. It reduces the mental load by changing the approach from "how to get the data" to simply *declaring what data you want and why you need that data*.

You can then later figure out, how you actually want to implement the query handler, whether it gets the data from a relational database or a web service.

That's another very positive aspect of this pattern:
You are finally able to *truly* abstract away the data store. Why? Because you declare what data you need and why you need it. With declaring the *why*, the query implementation knows exactly what data you need, so it can get all the data from the database needed for your exact scenario. This totally gets rid of all the problems lazy loading has, especially when trying to abstract the data store with an implementation of the repository pattern.

---
#### [trailmax](https://tech.trailmax.info/) - 03 December 13
I know it is 2 years since you've written it, but I only found it just now -)

We have started using mediator pattern recently and it saved us a lot of dependencies from being injected (see [this bit](https://tech.trailmax.info/2013/08/constructors-should-be-simple/))

You did mention a test to check if all queries have handlers. [Here](https://tech.trailmax.info/2013/12/test-all-you-query-to-have-queryhandler/) is my version of tests to check for that issue.

---
#### Phil - 3 December 13
Hi Steven, I'm in the same case as trailmax, I just found this article (Great job btw).

I just want to know how do you handle queries like find by id / find by primary key and findall?

---
#### Steven - 4 December 13
Phil, take a look at what trailmax does, I'm doing about the same in my applications. I define a generic `class GetByIdQuery<TEntity> : IQuery<TEntity>` query class.

---
#### Al - 9 December 13
Hi Steven,

I just found this series of articles and it is great, good job.
I have been interrested in CQRS for a while now and I think your articles are a good starting point.

Anyway, I was wondering, do you use any kind of messaging with this architecture in your actual projects (Brokered, Distributed) and if so, do you use any kind of abstraction on top of them?

---
#### Steven - 13 December 13
Al, it depends on the project. I just finished a project where we needed to build an application that worked in offline mode, and this meant adding caching decorators for queues and queuing commands for further processing. This was all based on the same `IQueryHandler` and `ICommandHandler` abstractions I wrote here on my blog.

---
#### Tom - 28 August 14
I think your principles are absolutely correct but in my experience this is in most scenarios over kill and ultimately reduces the amount of time you have to implement robust functionality which is after all what you are being paid for. Personally I have abstracted away like this too but after 15 years of coding I am moving back to less layers, more injection and simpler code. The benefits of infinite indirection and (bloody) Command patterns are minimal (I have never really seen a genuine benefit other than pretty symetries) over just coding the darn thing.

Basically what I find that works best is comprimise (depending on project goals and scale of course) and is vastly simpler:

1. Create 1 or more DataContexts to encapsulate data access, exposing POCO lists and objects directly to services, ui
2. Extend DataContexts with partial class to implement (stored proc simple data access code, include, dont expose IQuery in general but on occasion it can be helpful)
3. Extract interface for each DataContext (e.g IUserRepository) and inject that into Business logic services using IoC (define object full graph at start)
4. Testing is now straight forward, just use Moq to inject results directly into your system by create Moq().Setup(r => r.GetUsers()).Returns(new List())

That structure is simple and has all the benefits of this design but
1. frees developers from having to create endless classes to implement even basic functionality
2. reduces chance of bugs by reducing codebase
3. speeds up onboarding of new developers because its simple
4. easier to maintain and debug
5. easier to inject results with 1 moq class (the IDataContext)
6. did I say less code!!
7. faster build time
8. easier to refactor
9. happier developers

The number of overly engineered systems I am seeing these days is frightening, it seems developers are losing sight of the goals of their system and customer and getting caried away with abstracting abstration to the point that even a method is an IMethod. Its a poor developer who can't see the tradeoffs and where to draw the line, (Command pattern and Event aggregator I am looking at you!), it makes maintenance and further development an absolute nightmare! Oh and forget about handing the code over should you move on to another project or company..

My advice to new developers is to try to reduce the number of layers and indirection not increase them arbitarily like this! As long as with some dependency injection and a decent mocking frameworks you can mock out calls to your db then you are gonna be ok.

Anyway just my immediate thoughts and its a very good articles despite me disagreeing with some of your points!

Good luck

---
#### Peter - 09 August 13
@Tom,

I have to agree with you that when applying these abstractions there will be occasions where the logic contained in a class is little more than what would be a single method in a more conventional style development. However, working with these abstractions does pay dividends:

* SOLID principles: solutions built around these patterns promote a flexible and extensible code base, i.e. a SOLID code base
* Aspect Oriented Programming: a simple set of join-points for apply concerns without resorting to reflection and runtime generation (i.e. interception)
* Strategy Pattern: injecting functionality is great example of Inversion of Control

You are right when you imply that this may not be the only way to build an app but I've yet to see a well formed and fully justified argument for not following SOLID principles.

---
#### Paul - 29 August 13
@Tom, I find your reasoning about less abstractions, simpler and less code interesting. As a happy adopter of this design I have been questioning myself about the practical and impractical applications of this model. So far, I find it more practical than impractical. Let me elaborate...

At first, the setup may be intimidating, because you have to setup a few abstractions and implementations. But they all serve their purpose. Once setup there is little or no additional configuration needed, except dependencies like a datacontext that would need configuration anyway. After that it's writing commands (data holders) and their handlers (execution logic). All the dependencies are setup so datacontexts (unitofwork) only require inclusion through the constructor.

All that rests afterwards are simple data packages (commands or queries) and their handlers. A good setup (for example MVC) requires no less than a query handler service (i.e. IQueryProcessor) that handles the query (wires the query and handler) and returns the response. The same can be done with commands, but is not preferred.

Here is a real life example of a controller:

```
public class AccountController : Controller
{
    private readonly IQueryProcessor processor;
    public AccountController(IQueryProcessor processor) =>
        this.processor = processor;
    
    public ActionResult Index() => this.PartialView();
    
    public ActionResult GetOverview() =>
         this.Json(this.processor.Execute(new GetAccountOverviewQuery()));
    
    public ActionResult GetDetails(GetAccountDetailsQuery query) =>
        this.Json(this.processor.Execute(query));
}
```

Also on the corresponding query and its handler:

```
public sealed class GetAccountDetailsQuery : IQuery<AccountDetailView>
{
    public int Id { get; set; }
}

public sealed class GetAccountDetailsQueryHandler
    : IQueryHandler<GetAccountDetailsQuery, AccountDetailView>
{
    private readonly IAuthorizedRepository<Account> accounts;
    public GetAccountDetailsQueryHandler(
        IAuthorizedRepository<Account> accounts) => this.accounts = accounts;
    
    public AccountDetailView Handle(GetAccountDetailsQuery parameters) => (
        from account in this.accounts.Authorized()
        where account.Id == parameters.Id
        select account)
        .ToAccountDetailView()
        .Single();
}
```

Note that this setup is supported with dependency injection, the `IAuthorizedRepository<Account>` accounts is injected and can also easily be replaced with a mock when testing.

With a correct setup, this would be the only code you’d have to write. How is this over-engineered? The main goal is to separate data and logic with the extent of two interfaces. We have used standalone classes as you suggested, but the lack of generic logging, transaction management and more have pushed us towards this design. With great benefits I must say.

I agree with you that this kind of engineering may induce more and higher abstractions thus possible over-engineering. I also suffer from this syndrome, but it is a discipline you have to master anyway as a developer. There is no excuse for over engineering an application as well as under engineering. It’s a balance that must be found.

---
#### Steven - 14 December 14
For more information about applying paging and ordering to queries, please take a look at [this discussion](https://github.com/dotnetjunkie/solidservices/issues/3).

---
#### Masoud - 09 February 15
I'm working on a scheduling project so I defined a `ScheduleAmOrderCommand`, `ScheduleAnOrderCommandHandler` that schedule an `Order`, sometimes user wants to preview Order scheduling details in a gantt chart form (just after an order scheduled or for a before scheduled `Order`) so I don't know for the Preview I should define a `CommandHandler` or a `QueryHandler`?

---
#### [jgauffin](http://blog.gauffin.org/) - 09 February 15
@Masoud: Queries should be idempotent. That is, if you run the same query twice you should get the same result (unless a command have modified the state in between).

So you could consider queries to be read only, they only fetch the current state, while commands modify it.

Hence if the preview is just to check the state of the scheduling, define a query.

---
#### [Brent Arias](http://www.ariasamp.net/) - 11 October 15
Excellent material and presentation. One suggestion: the handler interface does not offer an asynchronous "HandleAsync" entry point. I think it would be better to convert the code so that it has *only* an asynchronous entry point. This would be analogous to the WebAPI `HttpClient` having only asynchronous entry points. The subsequent impact to the `QueryHandler` design would be minimal.

However, the impact to the `CommandHandler` is more interesting. A `CommandHandler` serving as a "use case" is perhaps best left with a synchronous entry point. However, a `CommandHandler` serving as a "repository" does indeed benefit from having an asynchronous entry point. I know that the "use case" idea is central to your POV, but I would rather use both the command / query handlers as a replacement for repositories. My reasoning is that my "use case" scenarios are more likely to be implemented as sagas rather than commands. Thoughts?

---
#### Steven - 11 October 15
Hi Brent,

Whether or not the use of async is useful and actually beneficial depends on a lot of factors, but in general I'm against just making everything asynchronous by default, as I explained [here](https://codereview.stackexchange.com/questions/84379/viewmodel-creator-design/84402#84402).

Furthermore, although asynchronous programming is difficult, making the `IQueryHandler` and `ICommandHandler` abstractions async is a no-brainer and that by itself is reason enough to not discuss it here in this blog post.

---
#### Sam - 23 October 15
Hi Steven,

I used this pattern of yours on a large project and it turned out great. I do have a question for you though and I'd be interested to hear your thoughts.

After using this pattern I (obviously) ended up with a project full of commands and queries, which gave me great visibility over my code base but it felt was like looking at a long list of stored procedures in a SQL database.

My problem is, it just doesn't feel very object oriented at all. In your first code snippet—the `IUserQueries` example—at least, it's clear to another developer what methods are available and what kind of queries they are, i.e. `UserQueries`. In the same way that a `User` object would expose similar methods.

But, short of placing all of my `User` related queries in a `User` folder or namespace in the project, I feel like there's no real structure to these random queries that I've written. Despite how readable my code is.

I wonder, did you ever have a similar impression or frustration and do you feel you can successfully practice things like Domain Driven Design whilst using this pattern?

Thanks!

---
#### Steven - 23 October 15
Hi Sam,

Whether or not something ‘feels object oriented’ or not, is irrelevant. The fact is that object-oriented principles are applied here, but even that isn't a means to an end. In the end, the only thing that really counts is total cost of ownership and these patterns can help tremendously in lowering the total cost of ownership by increasing code quality, development speed, and maintainability.

That doesn't mean, though, that code organization and project structure isn't important. Placing all command, query, handler, and related classes in the root folder of your project will probably only work for really small projects. The project structure should evolve with the size of your code base.

Here are some ideas to improve the project structure:

- Structure your code around sub systems of features. Each sub system or feature can have its own folder or project, and each folder or project.
- Separate queries from commands by placing them each in their own /Queries and /Commands folder.
- Group classes around main entities in your system such as `User` by placing them in folders named after that entity, e.g. /Users.
- Give each use case its own folder. This folder can contain the command, handler, and possible validators, security validators, business rules and other classes related to that use case.

Note that these solutions can be mixed and matched. In a former system I worked on we used all four approaches. Each sub system got its own project. Within such project the root contained both a /Queries and /Commands folder. Within the /Queries folder, we grouped code around the main entity. Within in the entity folder we placed each query, handler, and result classes inside a folder named after the query (so the `SearchAssetsQuery` class and handler where placed in the /SearchAssets folder). Although commands where placed inside a folder named after command (so the `CancelQuoteCommand` was placed in the /CancelQuote folder) we didn't group them in entity folders. This was because we had considerably less commands than queries.

I’m absolutely not saying that this is *the* way to structure your project. It is, however, a structure that worked well for us in our particular system. There are many ways to skin a cat, but the worst thing you can do is ditch this SOLID design, simply because you think it doesn't ‘feel object oriented’, because you get many small classes. Always remember what this model brings to the table compared to other types of design, and always find clean ways to structure your project, without losing the abilities that a design like this gives you.

---
#### [Brent Arias](http://www.ariasamp.net/) - 27 October 15
Hi Steven,

Do you have a philosophy regarding query handlers and REST? For example, when dealing with a web service you are authoring, perhaps you might prefer HTTP RPC instead of REST because it simplifies how query objects are serialized and deserialized (e.g. use JSON and treat *every* interaction as a `POST`). In contrast, if you embrace REST then you must employ a `GET` and then custom serialize/deserialize the query object through a URI.

If your answer is that you would still prefer to implement a web service RESTfully (with an appropriate query handler implementation on the client-side), would that include having the query responses contain hypermedia links to support HATEOAS? If yes, then I imagine other query or command objects might potentially take hypermedia links as part of their constructor. Thoughts?

---
#### Steven - 27 October 15
Hi Brent,

Take a look at the comments on [this post](/blogs/steven/p/maintainable-wcf). I think you'll find what you're looking for there.

---
#### Erik - 14 January 16
Is it acceptable for decorators of queries to have side effects (ie, the query is idempotent, but the decorator is not?). I'm primarily thinking about things like logging and caching here, which seem to be non-idempotent.

---
#### Steven - 15 April 16
Erik,

It is completely acceptible for the decorator to write stuff. The decorator will not cause a functional side effect, as in causing the query to return a different result the next time. In this sense, the system is still idempotent. It's pretty impossible to implement logging and audit trailing without storing information somewhere.

---
#### [Joseph Woodward](http://josephwoodward.co.uk/) - 01 March 16
I've experienced great results with this approach too. It definitely encourages a low cohesion architecture and service layer. There's a fantastic library called [Mediatr](https://github.com/jbogard/MediatR) for anyone interested in giving this approach a try.

---
#### Steven - 15 April 16
Joseph,

I personally don't advise depending on an external library for things that are essential parts of your architecture. Instead I advise to always define those few simple abstractions in your own application.

---
#### [Alexander Batishchev](https://blog.abatishchev.ru/) 12 March 16
Hi Steven, what do you think about [this approach](https://www.future-processing.pl/blog/cqrs-simple-architecture/)? It doesn't require using dynamic.

---
#### Steven - 15 April 16
Hi Alexander,

As you noticed, the design given on that blog allows a `QueryProcessor` that doesn't require dynamic. But I already described in my article that having a `IQueryProcessor` with an `Execute<TQuery, TResult>` method doesn't really work, because this makes execution queries awkward, since you'll need to specify both the query and result generic parameter when calling `Execute`. If you re-read the article, you will see this point made.

While the blog post you referenced gives an example of executing a command, it lacks an example of executing a query using its processor. I wonder if this omission is intentional, because if the writer would have added such example, it would have become clear immediately.

---
#### [Brent](http://ariasamp.net/) - 08 June 16
Using this approach, it seems to me that the cross-cutting concern of caching would require each query handler class to have a corresponding, unique caching decorator class. I love this overall approach enough that I am willing to write one caching decorator per query handler, but ... have you devised a strategy or technique that would allow one caching-decorator to handle all (or most) query handlers simultaneously?

---
#### Steven - 08 June 16
Hi Brent,

I usually just define one single generic caching decorator, but since you don't want to apply the same caching strategy per query, your registration needs might differ. But you could mark queries (or their handlers) with a customly defined `CachingAttribute` and apply your decorator conditionally, or register decorators one a per-handler basis. There are lots of options here, but I have never defined more than one caching decorator per application.

---
#### Dionisi - 03 July 16
Hello Steven,

The core logic of your design is to have a `QueryHandler` for each Query? Or could we have queries like `FindUserByIdQuery`, `FindUserByNameQuery` and get handled by the same handler `FindUserQueryHandler`?

---
#### Steven - 03 July 16
Hi Dionisi,

Most of the advantages that this model brings (as described in the post) are based around having message objects and a generic interface with one method. You won't lose any of those advantages if you pack multiple query handlers in one class. As a matter of fact, this model gives you the complete freedom to decide how to package your queries. Do be aware though that big classes with many query handlers can cause maintenance problems and having to add new query handlers to an existing class basically means violating both SRP and OCP, but at least the you can make these changes transparantly—you can add functionality without having to touch any other part of the system.

My preference is to give each query its own handler though, because this gives me a clean discoverable model, with a one-to-one relationship between definition (query) and implementation (the handler class).

---
#### Debby - 14 January 17
Hi Steven,

Do you mind expounding on why you "personally don't advise depending on an external library for things that are essential parts of your architecture...", specifically as it relates to MediatR? What are the disadvantages for example?

I am currently considering using MediatR for my next project. I have used this architecture based on your articles and sample code in the past, with success, and was thinking MediatR would be an easier and quicker way to get started.
---
#### Steven - 14 January 17
Hi Debbie,

You can find a more detailed reasoning about this in [this MediatR discussion](https://github.com/jbogard/MediatR/pull/101#issuecomment-246206384) that I participated in.

---
#### Fabian - 03 August 17
Hi Steven

Thanks for your interessting article. I'm trying to adopt this for my project. Is there a way to add a decorater which only get injected on specific return types (`TResult`)?

My goal is to check if a user who requests data via a query has the rights to read this data. I thought doing this in a decorator is a good way. Maybe i'm on the false path with the decorater for specific return types. What do you think? I cannot handle this generic because depending on the data which is requested i need to do another Validation.

Thanks and best regards

---
#### Steven - 03 August 17
Hi Fabian,

It's hard to answer this question, because it depends on the context whether or not conditionally applying a decorator based on some return type is the right approach or not, but it is certainly not a bad thing per se. On top of that, I can only comment on how to achieve such thing when using Simple Injector. If you like to know how to do this with Simple Injector, please post a question with more details about your case [here](https://simpleinjector.org/forum). If you like to know how to do this with a different library, please post the question at the appropriate forum for that library.

---
#### Rho - 13 September 17
Hi Steven,
what is not clear to me after reading the article: Did you drop the repositories in favor of the Query/Command handlers? At the beginning you mention

```
// Generic repository class (good)
public interface IRepository
...
// Custom entity-specific repository with query methods (awkward)
public interface IUserRepository : IRepository
...
```

Later there is no reference to `IRepository` anymore.

I think I need to keep the repository to construct business entities from (multiple) database tables and to make unit testing without database possible.

Thanks in advance
Rho

---
#### Steven - 13 September 17
Hi Rho,

It can be useful to wrap generic repository abstractions inside query handlers. It can also be useful to use non-generic repository abstractions for business operations, but you should be very careful with that, because of the problems with repositories as described in the article.

Be careful though with using `IRepository` as abstraction for testability in case you expose `IQueryable` from your repositories, because `IQueryable` is a leaky abstraction. This means that if you replace the `IQueryable` for an LINQ to Objects version during a unit test, there is still no guarantee that the tested query actually works in production. The reality is that classes that work with `IQueryable` can only tested in integration with a database.

---
#### Wheels - 26 September 17
hello Steven! very nice chain of articles in your blog. Have been ready through them and finding very useful to change not only my mind set but those around me.

I have a question about commands. In my project I normally use entity framework with code first to build my database and them use things like automapper to transform these objects to client objects to sent back to the client.

I have very complex forms that handle a lot of information and this normally means inserting/updating/removing from multiple tables in one single transaction. Currently I do this by injecting (using Simple Injector) a unit of work into my business layer that contains all repositories and a save changes. This unit of work instance contains a single EF context for all repositories allowing me to save all changes into a single transaction.

How do you implement this kind of complexity using commands? do you use `TransactionScope` on you business layers for this? and if you cant use it?

---
#### Steven - 26 September 17
Hi Wheels,

When applying these kinds of patterns, you can keep using your favorite ORM tool if you wish. You don't need to use a `TransactionScope` (as shown in [this article](/blogs/steven/p/commands) if all you need is one single `SaveChanges` call to your unit of work. In that case you can have a simple decorator that call SaveChanges for you.

---
#### [Alexander Batishchev](https://blog.abatishchev.ru/) - 26 September 17
In my app I had a handler decorator which was indeed creating and committing `TransactionScope` and a collection of handlers which were performing the actual work.

---
#### Wheels - 27 September 17
Thank you Steven for the reply. I'm still kind of new in this world and trying to grasp everything can me something a daunting task. I know you must be very busy but would it be possible for you to provide a sample application that implements these concepts? or point me to some github that has this implemented?

ty you :)

---
#### Steven - 27 September 17
Wheels, you can take a look at [this repository](https://github.com/dotnetjunkie/solidservices).

---
#### Rodrick - 18 October 17
Hey Steven! great article.

I'm going to start a new project very soon and want to refactor our current framework to use CQRS. This framework uses EF with the repository pattern with unit of work and a business layer where logging, automapping, unit of work are injected using Simple Injector (really love this DI). This business layer is nothing more than classes with methods that have crud in it. Then there is there Web API layer where these business classes are injected.

I was thinking in replacing this business layer with CQRS. I have some questions if you dont mind.

1. Would it be logical to remove this repository pattern and just inject the EF context into the command handler? or would you think this would be bad practice?

2. In case of negative on first question, would you still use repository pattern with unit of work injected into the command handler to guarantee a single transaction in case a command inserts in multiple tables or would you just use a decorator to insure this single insert? and in this case how could we use a decorator to guarantee this single transaction?

3. since the command handler knows what to do with the command, my current business layer seems redundant? would you just call the commands from the webapi?

Just a note about my repository pattern. I don't like the standard pattern in that it can become a anti-pattern with loads and load of different methods. So my repositories receive expressions and build the query internally. So the business constructs the queries as expressions and the repositories build the query. Here is an example:

```
var userEntity = UnitOfWork.EntityRepository
    .Get(o => o.EntityGuid == model.EntityGuid, e => e.EntityGroup)
    .FirstOrDefault();
```

So in this example the first part is the "Where" and the second part is the Include. The include part is a params parameter so you can include infinite navigation properties. Because if this most of my repositories are empty except for very complex queries. What do you think about this approach?

thank you.

---
#### Steven - 18 October 17
Hi Rodrick,

> 1) Would it be logical to remove this repository pattern and just inject the EF context into the command handler? or would you think this would be bad practice?

This is highly dependent on the system you are writing. On top of this command/query model, I've built several systems of different size and complexity. Here are the variations I used:

- Command handlers depending and interacting directly with a `DbContext`
- Command handlers interacting with a generic repositories, such as `IRepository` and `IEntityFactory`.
- Command handlers that use inline SQL directly.

You will have to decide what works best for you. Hiding your ORM from the command handlers can have interesting benefits such as testability and it allows to hide the quirks and complexities van the ORM tool. So injecting a `DbContext` into your command handler is not bad practice per se, it just depends on the system you are building.

On the query side it's different though. Query handlers are typically more connected to the physical data store, so it typically makes no sense in trying to abstract the ORM tool away. For instance, if you are querying over your database using LINQ, you take a hard dependency on the ORM. It's naive to think that by depending on IQueryable, you have abstracted your ORM, since `IQueryable` *is* a Leaky Abstraction.

It can still be useful though to let query handlers depend on some sort of `IRepository` abstraction instead of depending on `DbContext`. I used this approach in one of my systems where we needed to filter search results based on the user’s permissions (row based security). We were able to apply these filters transparantly by supplying query handlers only with an `IQueryable` that was returned from an `IRepository`). The repository abstraction allowed us to apply these filters transparently, with made it impossible for us to introduce bugs by forgetting to apply that filter on the level of the query handler. Still however, our query handlers were completely tied to Entity Framework and we had integration tests for them to verify them.

> 2) In case of negative on first question, would you still use repository pattern with unit of work injected into the command handler to guarantee a single transaction in case a command inserts in multiple tables or would you just use a decorator to insure this single insert? and in this case how could we use a decorator to guarantee this single transaction?

Again there are multiple possible answers. In case all changes can be made with a single call to `SaveChanges` on the `DbContext`, you can simply create a decorator that does so. If you require multiple cals to `SaveChanges`, multiple `DbContext`s or even to make changes outside the scope of a single `DbContext`, you have to wrap the whole operation in a transaction. The simplest thing that has worked for me for many years is to make use of `TransactionScope`. As long as you use a single connection string, it will not escalate to a distributed transaction, so it allows you simply wrap a complete operation in a transaction.

> 3) since the command handler knows what to do with the command, my current business layer seems redundant? would you just call the commands from the webapi?

Command and query messages *are* your domain. Those messages are *not* part of your business layer, they are part of the application's *contract* and can easily be shared with the client. This allows you expose them from your Web API. If you do this, the only thing a Web API has to do is pass that deserialized message on to the business layer. Command handlers will be part of your business layer. You could even take it one step further and remove all your controllers from your Web API. Just take a look at [this sample project](https://github.com/dotnetjunkie/solidservices/).

Whether or not command handlers will be the only components in your business layer depends on your application. For smaller applications, most meat will probably be in the command handlers, while in bigger applications, those handlers will probably depend on other services that do part of the work of the handler. This can go multiple layers deep.

> What do you think about this approach?

I don't have a clear answer to your repository model. Again, it depends on the application you are writing and what your needs are. If I understand your "includes", you are probably referring how you can include entities that need to be joined in the result. To me the EF include model is really bad. Entity Framework is able to automatically include stuff that it needs and lazy load the rest. In my experience lazy loading is fine when running command handlers (since performance of command handlers is hardly ever a problem). When creating query handlers on the other hand, you don't want to "include" stuff either, you just write a LINQ query and map it to a data object. You should never return entities from your LINQ query. You should *always* map to a data object. If you do this, you don't need "includes".

I hope this helps.

---
#### Rodrick - 19 October 17
Hey Steven! thank you for the quick response.

I opened the project and... holy crap it just blew my mind. I'm trying to wrap my head around that Web Api lool.

I was trying to change you example to work with OWIN and token authentication but cant seem to make it work since the controlless Web api disrupts the normal pipeline. Is it even possible?

---
#### Steven - 23 October 17
Hi Rodrick, I have little experience with OWIN, so I unfortunately can't comment on that.

---
#### RHo - 27 October 17
Hi Steven,
did you ever come across the need to pass filter criteria via query to the data source?

Like

```
GetUsersQuery : IQuery<Paged<User>>
```

with a property

```
public QueryFilter FilterCriteria get; set;
```

The intent is to pass `WHERE` criteria in the language of the domain layer to the data layer. I don't want to add a `Find()` method for each possible filter to the repository...

---
#### Steven - 27 October 17
Hi RHo,

Prevent defining generic filter criteria like structures onto query objects. I follow the following rules when it comes to defining these filters:

- Query arguments should be strongly typed, and specific to the query
- Query objects should be serializable
- Query handlers should be in full control over the shape of the SQL query.

By making the arguments (or filter properties) strongly typed, and specific to the query, query handlers are in complete control over which conditions it can filter, and how the SQL query will look like. This prevent scenarios that are untestable and un-tunable. For the same reason you should not let query handlers return `IQueryable<T>`, because that would leave filtering completely up to the client, which again makes it really hard to verify whether or not some client-specified filter actually works (you will have to test the client + handler together) and the handler again loses control over the shape of the SQL query, which can cause bad performance which is hard to fix.

Adding generic criteria objects can make it hard to impossible to serialize query objects. Being able to serialize queries is important for several reasons. First of all, you might want to expose query and command objects through a Web API or REST interface to prevent large amounts of code duplication of things that essentially are use case definitions anyway. Besides, serialization is important to be able to find slow performing queries or to build an audit trail.

From a functional perspective, the query should exactly specify what we expect the user to do. Stuffing in some criteria object might make sense at the ORM level, but not at a functional level.

This means a query should rather look like this:

```
public class GetUsersQuery : IQuery<UserInfo[]>
{
    public Guid? RoleId;
    public string SearchText;
    public bool? IsActive = true;
}
```

This query exactly specifies the conditions on which we can filter. All properties are nullable which means they can be left out of the filtering. For instance, if `RoleId = null`, the results will not be filtered by `RoleId`.

This doesn't mean however that we can't group common set of search values. If we have many queries with this same set of filter properties, we might be missing a common domain concept. In other words we might want to extract those properties to their own class and name it in a way that makes sense to the Domain Expert.

I hope this helps.

---
#### RHo - 27 October 17
Awesome answer!
While reading your reply, it hit me: what is a query object useful for, if not for passing filter criteria... :D
I'll add some more fields for range filters and comparison operators where needed. Thinking with webApi/wcf/multi client in mind helps a lot to keep the pieces in the right places.

Thanks!

---
#### Dani Avni - 31 October 17
Great article! I was trying to implement this on my project and got into some file organization question (Copying from a Stack Overflow question I posted on this without any answer yet). Because your design is basically CQS, I refer to it as such in the question below:

Upon reading and researching on a few problems in our code, I came across CQS (Command Query Separation) which makes more sense to me in our project as it will divide our huge service classes into smaller testable classes. I have successfully implemented a small prof of concept of this but I am now wondering how will my code be organized when I move 1000+ queries into the CQS namespace (concentrating on queries now as commands I imagine will be organized the same)- obviously putting all queries, handlers and results each in their own folder and each folder will have 1000+ files will be a huge pain to find something.

So far I have have this folder structure

* Model
 * `Customer`
* Queries
 * `CustomerNameByIdQuery`
 * `CustomerNameByTextSearchQuery`
* QueryHandlers
 * `CustomerNameByIdQueryHandler`
 * `CustomerNameByTextSearchQueryHandler`
* QueryResults
 * `CustomerNameQueryResult`

Both queries return the same `CustomerNameQueryResult` which has only `Id` and `Value` properties

Now imaging I need to query the full customer record as well so I would need a `CustomerByIdQuery`, `CustomerByIdQueryHandler` and a result from the Model of `Customer`. And currently there are about 10 other queries over customer with different parameters for different needs.

This pattern over hundreds of tables will make a lot of query classes and handlers making it really hard to find what I need to use at a specific place in code (promoting code re-use if possible).

I'm looking for advice from veterans who have been using CQS in a big production app about the organization of the namespaces/files of the queries in your project as for how is your solution organized for queries/handlers/results? For example do you put the query & handler in the same file? Separate files is separate directories? What do you do with multiple queries over the same object? Single file holding all queries or multiple files? Do you divide queries with namespaces for easier coding? Are there any problems you are aware of with your structure?

---
#### Steven - 31 October 17
Hi Dani,

Take a look at my comment from 23 October 15 in the comment section of this article. That comment gives some options in structuring your project.

> For example do you put the query & handler in the same file?

That's certainly an option, but it depends on the amount of reuse you require. Once you want to share queries and commands between client and server (by placing them in a shared dll), you will obviously need to separate them. If this is not required, placing messages and handlers in the same file makes navigation through code easier.

> What do you do with multiple queries over the same object?

Typically, I would give each query its own return type, although such return type could again be composed of reusable types.

> Single file holding all queries or multiple files?

Single file holding multiple queries is a model I use to get a team get accustomed to this model (since a typical model for developers is to group them around an entity, e.g. `IUserServices`, `IOrderServices`, `IProductServices`). After they are familiar with the model, developers typically start to place new queries in new classes by themselves, because that's simply more maintainable and browsable.

> Are there any problems you are aware of with your structure?

Every project is different and the amount of queries and handlers do dictate the amount of structure you need. Bigger projects need a more nested structure, just as they do without using this model. As described above, I use different models depending on the project I'm working on. Using modern refactoring tools, changing this structure is relatively easy, so I wouldn't get too hung up about this when starting. I typically change the structure at least once within the first year of the project.

---
#### Rodrick - 31 October 17
hey Steven,

I'm currently undergoing my refactoring on the company framework so it uses CQRS. Like I said we are using entity framework as our O/RM. A few things questions that I don't know how to solve has arisen:

1. I'm not really keen on having loads of `QueryById` types of queries. Is there a way we can have a `QueryById<Entity>` to avoid those?

2. On the writing side, when using entity framework, we typically get the object from the database first and then update the object. But if our repositories for the writing side only have update, delete and create available, how can we do this? not only that but there can be situations where we need to make a query (or queries) before updating for whatever reasons. How do you handles these on CQRS?

thanks again for being so active and helping everyone out :)

---
#### Tuukka Haapaniemi - 31 October 17
Hi Dani,

I've now had the CQRS structure Steven proposes here successfully in use for about two years, and this is the structure I've found to be the most convenient to work with, based on your example domain:

```
Customer
+-- Name
| +-- CustomerNameQueryResult.cs
| +-- ById
| | +-- CustomerNameByIdQuery.cs
| | +-- CustomerNameByIdQueryHandler.cs
| +-- ByTextSearch
| | +-- CustomerNameByTextSearchQuery.cs
| | +-- CustomerNameByTextSearchQueryHandler.cs
+-- ById
| +-- Customer.cs
| +-- CustomerByIdQuery.cs
| +-- CustomerByIdQueryHandler.cs
```

This way the folder structure starts relatively shallow, as in `ById` in this example, but can easily be deepened when need arises, such as in `Name` queries.

The main point I've found easiest is keeping the related files close to each other, because usually they change and evolve in these groups. Seldom do you write or change a query without touching the respective query handler and the response object too, or vice versa.

---
#### Tuukka Haapaniemi - 31 October 17
Hi Rodrick,

Tossing my 2 cents in here regarding your number 2 question:

The query side is the side benefiting the most from the CQRS separation, as that side can be left very lean. The writing side is the more complex side and as such is in no way limited of doing queries as well. In my solution I do just what you describe, albeit without the repository: The command handler retrieves the required data with EF, updates or adds what's required and saves everything back to the database. This query within the command handler is not in violation of the CQRS, in my opinion, as it only serves to complete the command.

The things I need from the database are completely different in the command to those things I need for a query, and so is the retrieval path and principle. For instance, in a query handler, the database context can be read only with no tracking, or even just a call to a stored procedure or plain SQL, but on the command side the context is read/write and tracking naturally enabled.

In the end my advice is not to get too strict with any architecture, or you'll end up creating an unnecessarily complex architecture in the process.

---
#### Steven - 31 October 17
Hi Rodrick,

I would like to prevent going into too detailed and specific questions here on my blog to prevent the comment section from exploding. I do love discussing these issues, so if you wish, you can post a more detailed question [here](https://github.com/dotnetjunkie/solidservices/issues). I'll do my best to give you my take on this.

