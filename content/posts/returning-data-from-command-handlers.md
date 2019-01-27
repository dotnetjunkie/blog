---
title:   "Returning data from command handlers"
date:    2012-04-14
author:  Steven van Deursen
tags:    [.NET General, Architecture, C#, Dependency Injection, ORM, Simple Injector]
draft:   false
aliases:
    - /p/data-commands
---

### This article extends the architectural design of command handlers to allow command handlers to return data.

#### **UPDATE: Although the article below might still be very entertaining, my opionion on the subject has changed. The problems described below will go away completely when you stop using use database generated IDs! Instead let the consumer of that command generate an ID (most likely a GUID). In this case, since the client creates the ID, they already have that value, and you don't have to return anything. This btw has other advantages, for instance, it allows commands to be executed asynchronously (or queued), without the need for the client to wait.**

A few months back I described the [command/handler architecture](/blogs/steven/p/commands/) that I (and many others) use to effectively model business operations in a system. Once in a while a question pops up in my mail or at Stackoverflow about returning data from a command.

It seems strange at first to return data from commands, since the whole idea of the Command-query separation is that a function should either return a value or mutate state, but not both. So without any more context, I would respond to such question with: separate the returning of the data from the operation that mutates the state. Execute that command and [execute a query](/blogs/steven/p/queries/) after the command has finished.

When we take a closer look at the question however, we will usually see that the data being returned is an Identifier of some sort, which is the result of the creation of some entity in the system. Take a look at the following command:

{{< highlight csharp >}}
public class CreateCustomerCommand
{
    public string Name { get; set; }
    public Address Address { get; set; }
    public DateTime? DateOfBirth { get; set; }
}
{{< / highlight >}}

Since the command will create a new customer, it’s not unlikely for the caller to need the id of the customer, for instance to redirect to another page:

{{< highlight csharp >}}
public ActionResult CreateCustomer(CreateCustomerCommand command)
{
    this.handler.Handle(command);
    int customerId = [get the customer Id here];
    return this.RedirectToAction("Index", new { id = customerId });
}
{{< / highlight >}}

Still, do we really want to return values from commands? A few things to note here. First of all, returning values from commands does mean that a command can never be executed asynchronously anymore, something that architectures such as [CQRS](https://martinfowler.com/bliki/CQRS.html) promote. Besides this, the `CreateCustomerCommand` seems very CRUDy, and probably doesn’t really fit an architecture like CQRS. In a CQRS like architecture, you are likely to report to your user the message “your request is being processed” or might want to poll until the operation has executed asynchronously.

For the systems I’m working on, for my customers, my fellow developers, and even myself, CQRS is a bridge too far. The idea of having all commands (possibly) execute asynchronously –and CQRS itself- is a real mind shift that I’m currently not willing to make (yet), and I can’t expect other developers to do to. With my current state of mind, it is simply too useful to have commands handlers return data to the caller. So how do we do that?

The answer is actually very simple: Define an ‘output’ property on a command:


{{< highlight csharp >}}
public class CreateCustomerCommand
{
    public string Name { get; set; }
    public Address Address { get; set; }
    public DateTime? DateOfBirth { get; set; }

    // output property
    public int CustomerId { get; internal set; }
}
{{< / highlight >}}

When a command handler sets this property during the execution, the caller can use it as follows:


{{< highlight csharp >}}
public ActionResult CreateCustomer(CreateCustomerCommand command)
{
    this.handler.Handle(command);
    int customerId = command.CustomerId;
    return this.RedirectToAction("Index", new { id = customerId });
}
{{< / highlight >}}

We can set this id from within the command handler:

{{< highlight csharp >}}
public class CreateCustomerCommandHandler
    : ICommandHandler<CreateCustomerCommand>
{
    private readonly NorthwindUnitOfWork unitOfWork;

    public CreateCustomerCommandHandler(NorthwindUnitOfWork unitOfWork)
    {
        this.unitOfWork = unitOfWork;
    }
 
    public void Handle(CreateCustomerCommand command)
    {
        var customer = new Customer
        {
            Name = command.Name,
            Street = command.Address.Street,
            City = command.Address.City,
            DateOfBirth = command.DateOfBirth,
        };

        this.unitOfWork.Customers.InsertOnSubmit(customer);

        this.unitOfWork.Commit();

        // Set the output property.
        command.CustomerId = customer.Id;
    }
}
{{< / highlight >}}

As you can see, the `CustomerId` property of the `CreateCustomerCommand` is set at the end of the `Handle` method of the handler. This sounds too good to be true, and well… it depends ;-).

When the `Customer.Id` is generated by the database, the `Commit` will ensure that the `Customer` is persisted and will retrieve the auto-generated key and it will become available immediately after the `Commit`. We can therefore simply set the command’s `CustomerId` property after calling `Commit`.

The previous command handler was in complete control over the unit of work. It created that unit of work, it committed that unit of work, and it disposed that unit of work. This is a simple model I effectively used in the past, and I know others are still using this today. Letting the command handler control the unit of work however, has its short comes.

This design works great when commands are small and contain little logic. It starts to fall apart however, when commands get more complex and start to depend on other abstractions that need to run in the same context / unit of work. When the unit of work is controlled by the command handler, it is the handler's responsibility of passing it on to its dependencies, and since those dependencies are already created at the time the handler creates the unit of work, constructor injection is out of the picture. The only thing left is passing the unit of work through method arguments (method injection). Although it doesn’t seem that bad, I worked on a system where we actually did this, but the call stacks were deep and passing around the unit of work from method to method, from class to class was just tedious. To make our lives easier we started creating a new unit of work for some operations, but this actually made things worse, since a single use case / request resulted in multiple unit of works, which sometimes lead to very strange behavior, or even deadlocks.

For this reason, I stepped away from this design and instead I inject a unit of work instance into classes that need it. Though, somebody somewhere in the system must manage the unit of work. This can be solved by registering the unit of work with a Per Thread or Per Web Request lifetime and implementing a command handler decorator that will ensure the unit of work is committed after the handler completed successfully (note that committing the unit of work on the end of the web request is typically a bad idea, since there is no way to tell whether the unit of work should actually be committed at that point). You have to realize that, although simplifying your application code, the complexity is moved into the [composition root](https://freecontent.manning.com/dependency-injection-in-net-2nd-edition-understanding-the-composition-root/). The size and complexity of your application must promote this. Although I must admit that once familiar with these types of constructions and configurations of your composition root, you will find it easy to apply in small systems as well.

One note about database generated keys. CQRS models the business around aggregate roots (a [DDD](https://en.wikipedia.org/wiki/Domain-driven_design) concept), and each aggregate root gets a unique key, usually generated as a Guid, which can be generated in .NET. This means that when using CQRS, you will never run into the problem of database generated keys, which is great of course.

Aggregates in DDD are a group of domain objects that belong together. The Aggregate Root is the thing that holds them together. An `Order` for instance, may have order lines and those order lines cannot live without that order. The order is therefore the aggregate root and has a unique (global) identifier. An order line does not need a (global) identifier (although they might have an local identifier, only known inside the aggregate), since it will never be referenced directly; other aggregates will only reference the order, never the lines. They may need an identitier in your relational database, but you would probably never return them from a command, since they are purely internal to the Aggregate. If such identitier is needed, returned, or referenced from other aggregates, they are probably not part of that aggregate and the system is incorrectly modeled, accordingly to DDD. This also means that the system will have not as many primary keys as a non-DDD system will have. In a normal relational database, each order line will usually get its own auto number primary key. In that case, it will be much more likely to get into performance problems when using Guids. A `Guid` is 16 bytes (12 bytes bigger than an `Int32`) and every database index of a certain table will contain the primary key of that table, making each index 12 bytes times the number of records in the table bigger. Disk space is cheap, but I/O isn’t. When doing complex queries over large amounts of data, lowering the amount of I/O is important. And don’t forget the clustered index fragmentation that random Guids cause.

Long story short, you might be in the situation where you don’t use DDD / CQRS, want to return a database generated value from your command handlers, while having a design were command handler don’t control the unit of work. How do we do this?

Since database generated IDs only come available after the data is saved to the database, and committing happens after the handler executed, we need a construct that allows the handlers to execute some code after the commit operation. We can introduce a new abstraction to the system, where command handlers can depend upon, which allows them to register some post-commit operation:


{{< highlight csharp >}}
public interface IPostCommitRegistrator
{
    event Action Committed;
}
{{< / highlight >}}

This interface defines a single event, which command handlers can depend upon and register their post commit operation. The previously defined `CreateCustomerCommandHandler` will now look like this:


{{< highlight csharp >}}
public class CreateCustomerCommandHandler
    : ICommandHandler<CreateCustomerCommand>
{
    private readonly UnitOfWork unitOfWork;
    private readonly IPostCommitRegistrator postCommit;

    public CreateCustomerCommandHandler(
        UnitOfWork unitOfWork, IPostCommitRegistrator postCommit)
    {
        this.unitOfWork = unitOfWork;
        this.postCommit = postCommit;
    }
 
    public void Handle(CreateCustomerCommand command)
    {
        var customer = new Customer
        {
            Name = command.Name,
            Street = command.Address.Street,
            City = command.Address.City,
            DateOfBirth = command.DateOfBirth,
        };
 
        this.unitOfWork.Customers.InsertOnSubmit(customer);
 
        // Register an event that will be called after commit.
        this.postCommit.Committed += () =>
        {
            // Set the output property.
            command.CustomerId = customer.Id;
        };
    }
}
{{< / highlight >}}

This command handler registers a delegate to the `IPostCommitRegistrator`, which is injected through the constructor (note that you should only inject the `IPostCommitRegistrator` into a handler that actually needs it).

From the application design, this really is all there’s to it. However, there is some more work to do inside the composition root. For instance, we need an implementation of this `IPostCommitRegistrator`:

{{< highlight csharp >}}
private sealed class PostCommitRegistratorImpl : IPostCommitRegistrator
{
    public event Action Committed = () => { };
 
    public void ExecuteActions()
    {
        this.Committed(); 
    }

    public void Reset()
    {
        // Clears the list of actions.
        this.Committed = () => { };    
    }
}
{{< / highlight >}}

This implementation is very simple. It just implements the `Committed` event and defines an `OnCommitted` method, which will be called from the code that manages the transactional behavior of the command handlers. In my previous post I defined an `TransactionCommandHandlerDecorator<T>`, which allowed executing the commands in a transactional manner. Although we can extend this class to add this post commit behavior, I like my classes to be focused, and have a single responsibility. Let’s define a `PostCommitCommandHandlerDecorator<T>`, that has the sole responsibility of executing the registered post commit delegates, after a transaction was committed successfully:

{{< highlight csharp >}}
private sealed class PostCommitCommandHandlerDecorator<T> : ICommandHandler<T>
{
    private readonly ICommandHandler<T> decorated;
    private readonly PostCommitRegistratorImpl registrator;
 
    public PostCommitCommandHandlerDecorator(
        ICommandHandler<T> decorated, PostCommitRegistratorImpl registrator)
    {
        this.decorated = decorated;
        this.registrator = registrator;
    }
 
    public void Handle(T command)
    {
        try
        {
            this.decorated.Handle(command);
 
            this.registrator.ExecuteActions();
        }
        finally
        {
            this.registrator.Reset();
        }
    }
}
{{< / highlight >}}

This decorator depends on the `PostCommitRegistratorImpl` directly and during the `Handle` method—after the transaction completes successfully—the `ExecuteActions` method of the `PostCommitRegistratorImpl` is called. Note that this decorator depends on the `PostCommitRegistratorImpl` implementation and not on the `IPostCommitRegistrator` interface. The interface does not implement the `ExecuteActions` method, and we don’t want it to, since we don’t want any command handler to call that method directly. We do however want this class to be able to execute the registered delegates, so we need it to access the implementation. Since both classes are part of the composition root, this is fine. The application code itself has no notion of the `PostCommitRegistratorImpl` nor the `PostCommitCommandHandlerDecorator<T>`.

Our last task is to wire up all the dependencies correctly. This isn’t really difficult, but does need a certain state of mind, since you need to carefully consider the lifestyle of `PostCommitRegistratorImpl`. Up until this point this article was container agnostic. Here is an example of how to configure this using [Simple Injector](https://simpleinjector.org/):

{{< highlight csharp >}}
container.Register(
    typeof(ICommandHandler<>), 
    typeof(ICommandHandler<>).Assembly);

container.RegisterDecorator(
    typeof(ICommandHandler<>), 
    typeof(TransactionCommandHandlerDecorator<>));

container.RegisterDecorator(
    typeof(ICommandHandler<>), 
    typeof(PostCommitCommandHandlerDecorator<>));
 
container.Register<PostCommitRegistratorImpl>(Lifestyle.Scoped);
container.Register<IPostCommitRegistrator, PostCommitRegistratorImpl>(
    Lifestyle.Scoped);
{{< / highlight >}}

The previous registration does a few things:

* First it registers all public `ICommandHandler<T>` implementations that live in the same assembly as the `ICommandHandler<T>` does.
* Next it registers the `TransactionCommandHandlerDecorator<T>` to be wrapped around each command handler implementation.
* Next it registers the `PostCommitCommandHandlerDecorator<T>` to be wrapped around each `TransactionCommandHandlerDecorator<T>` implementation. It is important that the post commit decorator is wrapped around the transaction decorator, since the system will behave incorrectly when they are decorated the other way around, since that means that the registered delegates would be called before the transaction is committed.
* The `PostCommitRegistratorImpl` is registered. Since we want to inject the same instance in both the command handler and the post commit decorator, we can’t use the transient lifestyle, since that will new up a new instance each time it is injected. Using a single instance for the whole application however, is only possible when the application is single-threaded (which can be the case if you run the handlers in a Windows Forms application or a Windows Service).
* Since the application does not depend on `PostCommitRegistratorImpl` but on the `IPostCommitRegistrator` interface, we need to register this as well.

## Conclusion

Again, one simple abstraction can solve the problem we have effectively. Nice about this design is that it keeps the code of the commands handlers pretty clean, and although not shown, it is easy to write unit tests for this as well.

## Comments

---
#### Jakub Konecki - 08 September 12
Very good article on CQRS. I really like your down to earth approach to the architecture. The previous two articles on commands and querying were equally informative.

I will be switching from Autofac to Simple Injector thanks to the ease of setting up configuration (i.e. decorators). Really powerful stuff!

I hope more post will be coming soon!

---
#### Michael - 28 November 12
I would suggest some naming convention over resulting properties (for example, Result***).

Also, why not using this approach for queries? This would allows us not only to return multiple data sets from queries without necessity of creating class for result, but also allows us reusing of decorators between commands and queries.

What do you think?

---
#### Steven - 28 November 12
Michael,

I've given this a lot of thought, but came to the conclusion that they deserve their own abstraction. Commands and queries are two completely separate concepts and therefore need to be handled differently. For instance, queries can never be queued while commands can never be cached.

The amount of code you will save by allowing a single decorator to both wrap queries and commands is minimal. And if there is a lot of code duplication between decorators, you are definitely missing an abstraction anyway. For instance, a `ValidationQueryDedocator` and a `ValidationCommandDecorator` should delegate the validation to a `IValidator` abstraction, instead of doing the validation their selves.

One could argue whether a command should in fact ever return a value. Still, most commands will never return a value. Queries on the other hand *always* return a value. This difference is so significant that they deserve a their own abstraction. Because queries always return a value, it would be awkward to mix the input and output. It would be awkward for the consumer that calls the query and it would be awkward for the decorator that caches this query.

---
#### Michael - 28 November 12
What decorator can wrap what command/query - it's decided uppon DI setup anyway - so yeah, you can put some inappropriate decorator around query or command but it's a kind of problem like giving wrong database `ISession` to handler.

Caching is indeed a valid point, but it still can be done with abstraction (cached data provider) which is also allowed handlers to tell the system if their result can be cached or not.

I think if we did follow full CQRS principles (event sourcing, bus, async support, etc.) then we indeed could not mix queries/commands into one abstraction. But I thought if we simplifying or view on archeticture a bit, we could also simplify their abstraction. So did you really found any crucial bonuses that separating query/commands abstractions gives? Because otherwise it's against YAGNI :)

---
#### Steven - 28 November 12
The fact that commands and queries have a different purpose should be enough to justify having two abstractions.

---
#### Arien Hartgers - 26 April 13
First of all I like your approach of your command handling without returning data in your previous article.

But reading this article I don't like the combination of input and output parameters in the command.
It is not a good thing not to separate the "input" and "output" parameters in the command. Now it is very hard to serialise the command over a WCF service without serializing the whole class with all information in both ways.

Further it's not clear which parameter is an input or an output parameter. This is very confusing for the consumer of the commands.

---
#### Michael - 26 April 13
Arien, what you suggest then? Events? But using events to only call commands inside you own code seems like overkill for me.

---
#### Steven - 26 April 13
Arien,

The points you note are valid.

For me it's not a big deal to have both input and output in a command, since the output should hardly ever be more than a generated ID. If you really wish to separate input and output, you can apply the same model for commands as I did in my previous article about queries (but even better of course is to simply not return anything). I think however that the benefits of this separation does not compensate for the extra complexity that defining the output type brings (since you should only return an ID).

The confusion between input and output parameters in the API of the commands can be solved by defining better names for the output properties, such as 'GeneratedCustomerId' or by using a convention to start a name with 'Output...'. Another option is to mark output properties with a special `[CommandResult]` or `[Output]` attribute. This communicates the intend of the property clearly.

Marking such property as `[Output]` is also a good solution when you want to minimize the trafic over the wire. The solution I take in the "SOLID Services" reference architecture application (https://solidservices.codeplex.com/) is to simply send the whole (possibly unchanged) command back over the wire. In the common case, commands should be fairly small and for most applications the overhead would be small enough for you to don't care about this. If your application is a special case where this performance or transport costs actually do matter, you can optimize the communication by using the [Output] attributes to determine which properties to return to the client (if any). I actually took this approach in the initial version of the reference architecture application, but I changed this to returning the whole command (in changeset 94202) to make the model simpler and more appealing to developers. If you look back in the changeset history (https://solidservices.codeplex.com/SourceControl/list/changesets) you can see how this was implemented. It's no rocket science.

---
#### Dimitri - 30 May 13
How about using 'out' keyword for getting back an `Id` in synch scenarios?

```
this.handler.Handle(command, out customerId);
```

I came across [an article](http://epic.tesio.it/doc/manual/command_query_separation.html) where they claim it to be a good practice.

Also domain logic validation which happens in the command handler which is a part of an application service might throw a custom exception with BrokenRules collection which could be caught by the client (MVC controller -> catch BrokenRulesException and append error list to ModelState)

Is it is safe path?

---
#### Steven - 30 May 13
Using an out parameter is just obfuscation and has no advantages over having a `Handle` method with a return value, i.e.:

```
int customerId = this.handler.Handle(command);
```

But consequence of a design with an `out` or return value is that the design of your command handlers will equal that of the [query handlers](/blogs/steven/p/queries/).

Such design for queries makes a lot of sense, because all queries return a value. For commands, however, such design is rather awkward, because, in general, commands should return `void` anyway. So why should we complicate this part in the design? Besides that, how do we handle the common void case? We can't define a command as `ICommand`, since C# (and the CLR) do not allow this. We could define our own `Void` type (something like `DbNull`) and have an `ICommand`, but that would still be awkward, and command handler in that case still have to do `return MyVoid.Instance;`.

To conclude: don’t do this. Stick to using return properties, or here is a better idea: Don't use database generated IDs! That completely solves the problem. Instead let the client generate an ID (most likely a `Guid`). In this case, because the client creates the ID, they already have that value, and you don't have to return anything. This btw has other advantages, for instance, it allows commands to be executed asynchronously (or queued), without the need for the client to wait.

When using GUIDs, you can either use sequential GUIDs (but in that case the client must know how to generate them) or you will have to defragment your database indexes once in a while (something you should probably be doing anyway).

---
#### Peter Leigh - 05 July 13
Steven,

How would you handle an `UpdateCustomer` method where I wanted to return the updated customer?

My first try is the following, but it looks really cumbersome, especially the repeated query calls

```
public ActionResult UpdateCustomer(UpdateCustomerCommand command)
{
    var customer = this.queryProcessor.Process(
        new FindCustomerByIdQuery(command.CustomerId));
    if (customer == null) return 404...;

    this.handler.Handle(command);

    var updatedCustomer = this.queryProcessor.Process(
        new FindCustomerByIdQuery(command.CustomerId));
    return Json(updatedCustomer);
}
```

Given that commands are one way, can thery throw exceptions?

If so you could do the following

```
public ActionResult UpdateCustomer(UpdateCustomerCommand command)
{
    try
    {
        this.handler.Handle(command);
    }
    catch (CustomerNotFoundException)
    {
        return 404...;
    }

    var updatedCustomer = this.queryProcessor.Process(
        new FindCustomerByIdQuery(command.CustomerId));
    return Json(updatedCustomer);
}
```

However neither way look particular elegant

Any help would be much appreciated, thank you for this great series of articles.

---
#### Steven - 05 July 13
> where I wanted to return the updated customer?

Commands should do nothing more than return the id, so if you need that customer after the command has executed, you will need to fetch that customer. A `FindCustomerByIdQuery` however seems like overhead to me. I'd rather use an `IRepository<Customer>`.

When using MVC on the other hand, you should redirect after a post instead of returning any data, so in that case you'll never have to fetch that record. I'm not sure what the the rules are when creating a Web API.

> Given that commands are one way, can thery throw exceptions?

Of course!!!! Any operation that does not deliver or do what it promisses to do should throw an exception. If that command can't update the customer, it should throw an exception. However, if you find yourself implementing many action methods with simple try-catch blocks where you return a custom error message, it becomes time to rethink your design. You might be able to implement a decorator or other mechanism that allows translating certain common exception messages to HTTP response codes. For instance, take a look at [this example](https://github.com/dotnetjunkie/solidservices/blob/master/src/WebApiService/Code/WebApiExceptionTranslator.cs) in the "Highly Maintainable Web Services" reference architecture project.

For this to work, prevent creating a specific exception per command. In other words, prevent creating `CustomerNotFoundException`, `OrderNotFoundException`, `OrderLineNotFoundException`, `CustomerAddressNotFoundException` etc. Rather, throw one single `KeyNotFoundException` for instance and translate this to a `HttpStatusCode.NotFound` code.

---
#### Meco - 27 July 13
Hi, interessting stuff.

What I currently cannot determine: Where and how to handle checks like 'new Product: Name already exists?'.

Should this be checked within an `AddNewProductCommand` and this command throws an excepton if the rule is violated? Or should this be checked before the command executes within Controller (asp.net mvc scenario). Or should the controller call a service thats checkes business rules like this and the service executes the command?

At this point i'am undecided whats the best code organizaton... Any (short) hint? Thanks a lot.

---
#### Steven - 28 July 13
Meco, there are many possible solutions when implementing validation. You can let the AddNewProductCommandHandler do its own validation, but I personally like to implement validations using an `IValidator<T>` interface and have an `IValidator<AddNewProductCommand>` implementation that validates the command by using its values and querying the database.

Although the `AddNewProductCommandHandler` could than depend on the `IValidator<AddNewProductCommand>` abstraction, a much nicer approach is to implement an `ValidationCommandHandlerDecorator<TCommand>` that wraps any command handler and depends on `IValidator<TCommand>`. An example of such decorator (The `ValidationQueryHandlerDecorator`) is given in [this article](/blogs/steven/p/queries/).

---
#### Josh - 17 October 13
Very good read, I'm starting a new project and looking for a different way to go about things. I really like the Command / Handler pattern, but when it comes to returning Id's / Objects - I have a question. When using entity framework, in your controller say you map from your ViewModel to your Domain entity (I'm planning on using Onion architecture), why not pass in the mapped core domain entity to the command handler? Example:

My entity is in Core.Domain.Customer

```
public class CreateCustomerCommandHandler : ICommandHandler<Customer>
{
    IUnitOfWorkFactory<NorthwindUnitOfWork> factory;

    public CreateCustomerCommandHandler(
        IUnitOfWorkFactory<NorthwindUnitOfWork> factory)
    {
        this.factory = factory;
    }

    public void Handle(Customer customer)
    {
        using (var unitOfWork = this.factory.CreateNew())
        {
            unitOfWork.Customers.InsertOnSubmit(customer);
            unitOfWork.Commit();
        }
    }
}
```

This should give you the ID back in the controller without specifying extra "Output" parameters. Can you think of any pitfalls to this?

---
#### Steven - 17 October 13
Hi Josh,

Seems to me that what you need is an `IRepository<TEntity>` instead of an `ICommandHandler<TCommand>` (the repository pattern, see: https://martinfowler.com/eaaCatalog/repository.html.

---
#### Josh - 17 October 13
Hrmm ... I guess what I am getting at is that I want to move away from using repositories / God like service classes to using the command / query handlers. Do you see a problem with using my actual Domain Entity with the CommandHandler instead of a `UpdateCustomerCommand` which is just a DTO? Since I am using EF, the objects ID is populated by EF and I don't have to worry about returning anything - its on my entity after the `Insert`.

---
#### Andreas - 19 July 14
I know it is a bit old, but the following comment sparked some questions for me.

> A `FindCustomerByIdQuery` however seems like overhead to me. I'd rather use an `IRepository<Customer>`

Wasn't a main point to get away from repositories and make specific single responsibility classes? Why do you think a `FindCustomerByIdQuery` shouldn't exist and be in a repository instead? How would you decide when something is a query (or a command) or just another method in a repository?

If I make a generic repository what methods would you have on there?

---
#### Steven - 19 July 14
Andreas, they aren't mutually exclusive. On the last project I participated in we used a generic `GetByIdQuery<T>` query class. This was very convenient for us, because communication between PL and BL was done through WCF (we had multiple Win Forms client apps). By using `GetByIdQuery<T>` we were able to keep the WCF layer as thin as possible (as described [here](/blogs/steven/p/maintainable-wcf)) and free from any maintenance. However, letting our Form classes depend on things like `IQueryHandler<GetByIdQuery<Customer>, Customer>` however wasn't very nice in our opinion (too much generic typing), so on the client we defined an `IRepository<T>` abstraction where the only existing open generic implementation internally just executed the `IQueryHandler<GetByIdQuery<T>, T>` query.

---
#### Alex Fox Gill - 17 December 14
I know this is a fairly old post but I have had some success in returning results from a command handler. Your ID creation example is a good one but there are other times when you might want to know what the results of a command were - for example, validation failures that stopped the command being executed.

My approach is to define a marker interface, `ICommandResult`. Command handlers then return an `IEnumerable`.

It is fine for a command handler to simply `yield break`. Or they may want to return an `EntityCreatedResult`:

```
public class EntityCreatedResult : ICommandResult
{
    public TEntity Entity { get; private set; }

    public EntityCreatedResult(TEntity entity)
    {
        Entity = entity;
    }
}
```

Or perhaps a `ValidationError`:

```
public class ValidationError : ICommandResult
{
    public string Message { get; private set; }

    public ValidationError(string message)
    {
        Message = message;
    }
}
```

Consumers can then operate on the list of `CommandResults`:

```
var results = _commandHandler.Handle(cmd);
var errors = results.OfType().ToList();

if (errors.Any())
    throw new InvalidOperationException();

var id = results.OfType().Select(e => e.Entity.Id).First();
```

I think the return type of `IEnumerable` adequately conveys the distinction between QueryHandler and CommandHandler here, and if your command handler doesn't have any result there is no impetus to do so.

---
#### Steven - 17 December 14
Hi Alex,

I would advise against returning anything but simple IDs from your commands. For me the rule is simple: if a command handler can't do what it promises to do, an exception should be thrown. This holds for validation errors and any other exceptional condition. This keeps your interface clean, and disallows the consumer to forget about those errors.

Besides this, after a few years of experience I found that using GUIDs as IDs is much better, because this allows you to let the consumer generate the ID and pass it on with the command as input argument. This way you don't have to return anything at all.

---
#### Eugene Khudoy - 21 December 14
Thank you for yoyr article. I have a question regarding:

> I would advise against returning anything but simple IDs from your commands

I saw similar statements (commands shall return void) in other articles about CQRS, but what about reasons? If I return something from command, what problems it may lead to?

---
#### Steven - 21 December 14
Hi Eugene,

According to the [Command-Query Separation](https://en.wikipedia.org/wiki/Command%E2%80%93query_separation) (CQP) principle, an operation should either change the state of the system -or- return something, never both. It is a way of simplifying the system. When your command starts to return data, it begins to behave like a query, which makes a system more complex.

You might make an exception to this rule when it comes to database generated IDs (as I did in the past), but that still complicates your design, and prevents you from being able to apply other features in the future, such as placing commands in a queue for improved robustness. When you queue them, the return value is not immediately available to the sender, which basically means there is no return value.

That's one reason I rather not have any return values on my commands. I rather let the sender of the command generate the ID upfront. This way, it is not dependent on any return value from the command.

