---
title:  "Meanwhile... on the command side of my architecture"
date:   2011-12-11
tags:   [.NET General, Architecture, C#, Dependency Injection]
draft:  false
aliases:
    - /p/commands
---

### This article describes how a single interface can transform the design of your application to be much cleaner, and more flexible than you ever thought possible.

#### Chapter 10 of [my book](https://manning.com/seemann2) contains a much more elaborate version of this article.

Since I began writing applications in .NET I've been separating operations that mutate state (of the database mostly) from operations that return data. This is basically what the [Command-query separation principle](https://en.wikipedia.org/wiki/Command-query_separation) is about. Over time the designs I have used have evolved. Initially triggered by a former colleague of mine I started to use the [Command Pattern](https://en.wikipedia.org/wiki/Command_pattern) about four years ago. Back then we called them business commands and a single command would represent an atomic business operation, or [use case](https://en.wikipedia.org/wiki/Use_case).

Over the years, the projects I have participated on have increased in complexity and I have adopted newer techniques such as [Test Driven Development](https://en.wikipedia.org/wiki/Test-driven_development) and [Dependency Injection](https://en.wikipedia.org/wiki/Dependency_injection) (DI). The flaws in this approach to the Command Pattern have become obvious to me. DI has a tendency of exposing violations of the [SOLID principles](https://en.wikipedia.org/wiki/SOLID) and this implementation hindered the maintainability of these applications.

In the early days my implementation of the Command Pattern design consisted of classes that contained both properties to hold the data and an `Execute()` method that would start the operation. The design had an abstract `Command` base class that contained all of logic for handling transactions, re-executing commands after a deadlock occurred, measuring performance, security checks, etc. This base class was a big [code smell](https://en.wikipedia.org/wiki/Code_smell) and was a form of [God Object](https://en.wikipedia.org/wiki/God_object) with many responsibilities. Furthermore, having data and behavior interleaved made it very difficult to mock/abstract that logic during unit testing. For example a consumer of a command would typically new up a command instance and call `Execute()` directly on it, as shown in the following example:

{{< highlight csharp >}}
var command = new MoveCustomerCommand
{
    CustomerId = customerId,
    NewAddress = address
};

command.Execute();
{{< / highlight >}}

I tried to solve this problem by injecting the command into the constructor of a consumer (constructor injection), but this was awkward to say the least. It remained the responsibility of the consumer to set all the properties of the object that was passed in and didn't really solve the problem of abstracting away the command elegantly. To prevent the command's logic from being executed, I had to define a fake version of each command for testing and it did nothing to reduce the large and complicated base class.

All of these experiences led me to try a design that I had seen others use, but that I had never seen the benefits of. In this new design, data and behavior are separated. Each business operation has a simple data container called the command object; my standard naming convention for these classes is to suffix them with 'Command':

{{< highlight csharp >}}
public class MoveCustomerCommand
{
    public int CustomerId { get; set; }
    public Address NewAddress { get; set; }
}
{{< / highlight >}}

The logic gets its own separate class; my standard naming convention for these classes is to suffix them with 'CommandHandler':

{{< highlight csharp >}}
public class MoveCustomerCommandHandler
{
    private readonly UnitOfWork db;

    public MoveCustomerCommandHandler(
        UnitOfWork db,
        [Other dependencies here])
    {
        this.db = db;
    }
 
    public virtual void Handle(MoveCustomerCommand command)
    {
        // TODO: Logic here
    }
}
{{< / highlight >}}

This design gives us a lot; a command handler can be injected into a consumer, while the consumer can simply new up the related command object. Because the command only contains data, there no longer a reason to fake the command during testing. Here’s an example of how a consumer can use that command and command handler:

{{< highlight csharp >}}
public class CustomerController : Controller
{
    private readonly MoveCustomerCommandHandler handler;
 
    public CustomerController(MoveCustomerCommandHandler handler)
    {
        this.handler = handler;
    }
 
    public void MoveCustomer(int customerId, Address newAddress)
    {
        var command = new MoveCustomerCommand
        {
            CustomerId = customerId,
            NewAddress = newAddress
        };
 
        this.handler.Handle(command);
    }
}
{{< / highlight >}}

There is still a problem with this design. Although every handler class has a single (public) method (and therefore adheres the [Interface Segregation Principle](https://en.wikipedia.org/wiki/Interface_segregation_principle)), all handlers define their own interface (there is no common interface). This makes it hard to extend the command handlers with new features and cross-cutting concerns. For example, we would like to measure the time it takes to execute every command and log this information to the database. How can we do this? In the past we would either change each and every command handler, or move the logic into a base class. Moving this feature into the base class is not ideal as the base class will soon contain lots of these common features, and would soon grow out of control (which I have seen happening). Besides, this would make it hard to test derived types and enable/disable such behavior for certain types (or instances) of command handlers because it would involve adding conditional logic into the base class, making it even more complicated!

All these problems can be solved elegantly by having all command handlers implement a single generic interface:

{{< highlight csharp >}}
public interface ICommandHandler<TCommand>
{
    void Handle(TCommand command);
}
{{< / highlight >}}

Using this interface, the `MoveCustomerCommandHandler` would now look like this:

{{< highlight csharp >}}
// Exactly the same as before, but now with the interface.
public class MoveCustomerCommandHandler
    : ICommandHandler<MoveCustomerCommand>
{
    private readonly UnitOfWork db;

    public MoveCustomerCommandHandler(
        UnitOfWork db,
        [Other dependencies here])
    {
        this.db = db;
    }
 
    public void Handle(MoveCustomerCommand command)
    {
        // TODO: Logic here
    }
}
{{< / highlight >}}

One important benefit of this interface is that it allows the consumers to depend on the new abstraction, rather than a concrete implementation of the command handler:

{{< highlight csharp >}}
// Again, same implementation as before, but now we depend
// upon the ICommandHandler abstraction.
public class CustomerController : Controller
{
    private ICommandHandler<MoveCustomerCommand> handler;
 
    public CustomerController(ICommandHandler<MoveCustomerCommand> handler)
    {
        this.handler = handler;
    }
 
    public void MoveCustomer(int customerId, Address newAddress)
    {
        var command = new MoveCustomerCommand
        {
            CustomerId = customerId,
            NewAddress = newAddress
        };
 
        this.handler.Handle(command);
    }
}
{{< / highlight >}}

What does adding an interface give us? Well frankly, a lot! Since nothing depends directly on any implementation but instead depends on an interface, we can now replace the original command handlers with any class that implements the new interface. Ignoring, for now the usual argument of testability, look at this generic class:

{{< highlight csharp >}}
public class TransactionCommandHandlerDecorator<TCommand>
    : ICommandHandler<TCommand>
{
    private readonly ICommandHandler<TCommand> decorated;
 
    public TransactionCommandHandlerDecorator(
        ICommandHandler<TCommand> decorated)
    {
        this.decorated = decorated;
    }
 
    public void Handle(TCommand command)
    {
        using (var scope = new TransactionScope())
        {
            this.decorated.Handle(command);
 
            scope.Complete();
        }
    }
}
{{< / highlight >}}

This class wraps an `ICommandHandler<TCommand>` instance (by accepting an instance of the same interface in its constructor), but at the same time it also implements the same `ICommandHandler<TCommand>` interface. It is an implementation of the [Decorator pattern](https://en.wikipedia.org/wiki/Decorator_pattern). This very simple class allows us to add transaction support to all of the command handlers.

Instead of injecting a `MoveCustomerCommandHandler` directly into the `CustomerController`, we can now inject the following:

{{< highlight csharp >}}
var handler =
    new TransactionCommandHandlerDecorator<MoveCustomerCommand>(
        new MoveCustomerCommandHandler(
            new EntityFrameworkUnitOfWork(connectionString),
            // Inject other dependencies for the handler here
        )
    );
 
// Inject the handler into the controller’s constructor.
var controller = new CustomerController(handler);
{{< / highlight >}}

This single decorator class (containing just 5 lines of code) can be reused for all of the command handlers in the system.

In case you're still not convinced, let's define another decorator:

{{< highlight csharp >}}
public class DeadlockRetryCommandHandlerDecorator<TCommand>
    : ICommandHandler<TCommand>
{
    private readonly ICommandHandler<TCommand> decoratee;
 
    public DeadlockRetryCommandHandlerDecorator(
        ICommandHandler<TCommand> decoratee)
    {
        this.decoratee = decoratee;
    }
 
    public void Handle(TCommand command)
    {
        this.HandleWithRetry(command, retries: 5);
    }
 
    private void HandleWithRetry(TCommand command, int retries)
    {
        try
        {
            this.decoratee.Handle(command);
        }
        catch (Exception ex)
        {
            if (retries <= 0 || !IsDeadlockException(ex))
                throw;
 
            Thread.Sleep(300);
 
            this.HandleWithRetry(command, retries - 1);
        }
    }
 
    private static bool IsDeadlockException(Exception ex)
    {
        return ex is DbException 
            && ex.Message.Contains("deadlock")
            ? true
            : ex.InnerException == null
                ? false
                : IsDeadlockException(ex.InnerException);
    }
}
{{< / highlight >}}

This class should speak for itself—although it contains more code than the previous example, it is still only 14 lines of code. In the event of a database deadlock, it will retry the command 5 times before it leaves the exception bubble up through the call stack. As before we can use this class by wrapping the previous decorator, as follows:

{{< highlight csharp >}}
var handler =
    new DeadlockRetryCommandHandlerDecorator<MoveCustomerCommand>(
        new TransactionCommandHandlerDecorator<MoveCustomerCommand>(
            new MoveCustomerCommandHandler(
                new EntityFrameworkUnitOfWork(connectionString),
                // Inject other dependencies for the handler here
            )
        )
    );

var controller = new CustomerController(handler);
{{< / highlight >}}

By the way, did you notice how both decorators are completely focused? They each have just a single responsibility. This makes them easy to understand, easy to change - this is what the [Single Responsibility Principle](https://en.wikipedia.org/wiki/Single_responsibility_principle) is about.

The downside of these changes is that it can require a lot of boilerplate code to wire up all the classes that depend on a command handler; but at least the rest of the application is oblivious to this change. When dealing with any more than a handful of command handlers you should consider using a Dependency Injection library. Such a library can automate this wiring for you and will assist in making this area of your application maintainable.

The system depends on the correct wiring of these dependencies, since wrapping the deadlock retry behavior with the transaction behavior would lead to unexpected behavior (since a database deadlock typically has the effect of the database rolling back the transaction, while leaving the connection open), but this is isolated to the part of the application that wires everything together. Again, the rest of the application is oblivious.

Both the transaction logic and deadlock retry logic are examples of [cross-cutting concerns](https://en.wikipedia.org/wiki/Cross-cutting_concern). The use of decorators to add cross-cutting concerns is the cleanest and most effective way to apply these common features. It is a form of [Aspect Oriented Programming](https://en.wikipedia.org/wiki/Aspect-oriented_programming). Besides these two examples there are many other cross-cutting concerns I can think of that can be added fairly easy using decorators:

* [checking the authorization](https://github.com/dotnetjunkie/solidservices/issues/4) of the current user before commands get executed,
* [validating](https://simpleinjector.org/aop#decoration) commands before commands get executed, 
* measuring the duration of executing commands, 
* logging and audit trailing,
* executing commands [in the background](https://simpleinjector.org/advanced#decorators-with-func-t-decoratee-factories), or
* queuing commands to be processed in a different process.

> **Background story:** This last point is a very interesting one. Years ago I worked on an application that used a database table as queue for commands that would be executed in the future. We wrote business processes (commands by themselves) that sometimes queued dozens of other (sub) commands, which could be processed in parallel by different processes (multiple Windows services on different machines). These commands did things like sending mail or heavy stuff such as payroll calculations, generating PDF documents (that would be merged by another command, and sending those merged documents to a printer by yet another command). The queue was transactional, which allowed us to -in a sense- send mails and upload files to FTP in a transactional manner. However, We didn't use dependency injection back then, which made everything so much harder (if only we knew).

Because commands are simple data containers without behavior, it is very easy to serialize them (using the `XmlSerializer` for instance) or send them over the wire (using WCF for instance), which makes it not only easy to queue them for later processing, but ot also makes it very easy to log them in an audit trail- yet another reason to separate data and behavior. All these features can be added, without changing a single line of code in the application (except perhaps a line at the start-up of the application).

This design makes maintaining web services much easier too. Your (WCF) web service can consist of only one 'handle' method that takes in any command (that you explicitly expose) and can execute these commands (after doing the usual authentication, authorization, and validation of course). Since you will be defining commands and their handlers anyway, your web service project won't have to be changed. If you're interested, take a look at my article [Writing Highly Maintainable WCF Services](/steven/p/maintainable-wcf/).

One simple `ICommandHandler<TCommand>` interface has made all this possible. While it may seem complex at first, once you get the hang of it (together with dependency injection), well... the possibilities are endless. You may think that you don’t need all of this up front when you first design your applications but this design allows you to make many unforeseen changes to the system later without much difficulty. One can hardly argue a system with this design is over-engineered, since every business operation has its own class and we have put a single generic interface over them all. It’s hard to over-engineer that - even really small systems can benefit from [separating concerns](https://en.wikipedia.org/wiki/Separation_of_concerns).

This doesn't mean things can’t get complicated. Correct wiring all of these dependencies, and writing and adding the decorators in the right order can be challenging. But at least this complexity is focused in a single part of the application (the start-up path a.k.a. [Composition Root](https://freecontent.manning.com/dependency-injection-in-net-2nd-edition-understanding-the-composition-root/)), and it leaves the rest of the application unaware and unaffected. You will rarely need to make sweeping changes across your application, which is what the [Open/Closed Principle](https://en.wikipedia.org/wiki/Open/closed_principle) is all about.

By the way, you probably think the way I created all those decorators around a single command handler is rather awkward, and imagined the big ball of mud that it would become after we have created a few dozen command handlers. Yes, you are right - this doesn’t scale well. But as I already mentioned, this problem is best resolved with a DI library. For instance, when using [Simple Injector](https://simpleinjector.org), registering all command handlers in the system can be done with a single line of code. Registering a decorator is another single line. Here is an example configuration when when using [Simple Injector: 

{{< highlight csharp >}}
var container = new Container();

// Go look in all assemblies and register all implementations
// of ICommandHandler<T> by their closed interface:
container.Register(
    typeof(ICommandHandler<>),
    AppDomain.CurrentDomain.GetAssemblies());

// Decorate each returned ICommandHandler<T> object with
// a TransactionCommandHandlerDecorator<T>.
container.RegisterDecorator(
    typeof(ICommandHandler<>),
    typeof(TransactionCommandHandlerDecorator<>));

// Decorate each returned ICommandHandler<T> object with
// a DeadlockRetryCommandHandlerDecorator<T>.
container.RegisterDecorator(
    typeof(ICommandHandler<>),
    typeof(DeadlockRetryCommandHandlerDecorator<>));

// Decorate handlers conditionally with validation. In
// this case based on their metadata.
container.RegisterDecorator(
    typeof(ICommandHandler<>),
    typeof(ValidationCommandHandlerDecorator<>),
    c => ContainsValidationAttributes(c.ServiceType));

// Decorates all handlers with an authorization decorator.
container.RegisterDecorator(
    typeof(ICommandHandler<>),
    typeof(AuthorizationCommandHandlerDecorator<>));
{{< / highlight >}}

No matter how many command handlers you add to the system, these few lines of code won’t change, which also helps to underline the true power of a DI library. Once your application is built applying the SOLID principles, a good DI library will ensure that the startup path of your application remains maintainable.

This is how I roll on the command side of my architecture.

## Further reading

* If you found this article interesting, you should also read my follow up: [Meanwhile... on the query side of my architecture](/steven/p/queries/).
* In [Writing Highly Maintainable WCF Services](/steven/p/maintainable-wcf/) I talk about sending commands over the wire
* If you want to learn how to migrate your existing application to use this model, please read [this thread](https://github.com/simpleinjector/SimpleInjector/issues/520#issuecomment-368907098).
* Chapter 10 of [my book](https://manning.com/seemann2) contains a much more elaborate version of this article.

## Comments

---
#### Evaldas Dauksevičius - 23 December 11

SOLID article! thanks! :)

---
#### Ian - 27 August 12
I had been playing with a few similar concepts, reading your article really helped me get to grip on what it was I was trying to achieve, a great help thanks!

---
#### Alexey Zuev - 11 November 12
Nice article, thanks! Interesting ideas and well explained.

I have a question of how you handle cases (or how you manage not to have them) when a consumer is interested in a result of the command handling? For example, when command is - entity creation and consumer is interested in entity id which is generated during command handling.

---
#### Steven - 11 November 12
Hi Alexey,

Returning data from command handlers is something I explain in [one of my later articles](/steven/p/data-commands).

---
#### Rick - 28 November 12
Great series of articles.

Should there only ever be one command handler for a command? My assumption is yes and that the handler can raise domain events for further participation. This makes sense if you consider a command & handler as corresponding to use cases.

---
#### Steven - 29 November 12
Rick, if you have more than one command handler per command, there might be something wrong with your design. A command handler is the implementation of a use case and it should be atomic, so IMO it makes little sense to split this up in multiple handlers. For event handlers on the other hand, it would be very likely to have multiple.

---
#### Dzenan - 24 February 13
Hi Steven,

great article thank you for sharing it.

I wonder if `MoveCustomerCommand` could be interface instead? Do you see any problems with it?

Best regards

---
#### Steven - 24 February 13
Hi Dzenan,

Let me turn it the other way around: what would be the use of adding an interface to a command message? Since a command message only contains data, and no logic, there should be no reason ever to abstract that type, as you would typically only abstract *behavior*, not data.

So the problem is that you will create a useless abstraction that will only be in the way when writing your application, writing your tests and wiring your application in the DI Container.

This doesn't mean, however, that your commands can't implement any interfaces. On the contrary, interfaces can help you in applying cross-cutting concerns conditionally in a very natural way. Take a look at this command and decorator:

```
public class ShipOrderCommand : IAsyncCommand { }

public class AsyncCommandHandlerDecorator<T> : ICommandHandler<T>
    where T : IAsyncCommand
{
    // logic
}

// The decorator will automatically be applied to command
// handlers that satisfy the generic type constraint.
container.RegisterDecorator(
    typeof(ICommandHandler<>),
    typeof(AsyncCommandHandlerDecorator<>));
```

---
#### mike - 01 June 13
Hello great article.
Would it be bad practice to call commands from within a command? Or should you call each single command from the controller?

---
#### Steven - 01 June 13
Mike, although this isn't bad practice per see, I think it's best to define a command as an atomic operation and use `ICommandHandler<T>` only as abstraction between the presentation layer and the business layer (not within the BL). This makes it much easier to apply cross-cutting concerns to command handlers, since most cross-cutting concerns should not be applied to the inner command handlers (i.e. you don't want to start a new transaction for an inner command).

---
#### sean - 07 June 13
Hello Steven,
Excellent article. I got a lot from it. Thanks. Was just wondering if you implemented this behind an MVC site which used ViewModels to display data, would you recommend still having the command DTO's to pass to the command handlers? Then I will have 2 levels of mapping to do - View Model to Command, then Command to Domain Entity. Which will give me a nicer abstraction if a different client was to use the commands. But in my scenario it leads to some DTO repetition. What are your thoughts on this? Thanks for your time.

---
#### Steven - 07 June 13
Hi Sean,

MVC has great model binding and compile time capabilities, so in general I would not recommend creating view models that are duplicates of your commands. Instead use the command as property in your view model. Example:

```
public class MoveCustomerViewModel
{
    public MoveCustomerCommand Command { get; set; }

    // Extra properties needed to render the view
    public IEnumerable Customers { get; set; }
}
```

---
#### Paul Seabury - 03 July 13
Steven - Great article & info, thanks!

One question I have is what if a controller might want to call 10 different commands... doesn't that get a bit burdensome with constructor injection? The registration part is easy with Simple Injector for example, but it seems unwieldy to inject so many dependencies via constructor.

---
#### [Daniel Hilgarth](https://www.fire-development.com/) - 03 July 13
Paul,
I take the liberty to answer that.

If your class needs ten different commands it most likely violates the Single Responsibility Principle.

There are two different ways you can fix it. Which one is appropriate depends on your actual class.

Scenario 1:
Your class has several public methods, each of which uses only a subset of the provided commands.
Solution: Break your class apart into smaller classes, each with a focused responsibility

Scenario 2:
All ten commands are used by one method.
This means that there probably is an abstraction that you didn't yet extract.
Solution: Extract an abstraction that encapsulates a part of your method and exposes a higher level interface and internally uses some of the commands.
[Further reading](https://blog.ploeh.dk/2010/02/02/RefactoringtoAggregateServices/) for this scenario.

---
#### Steven - 03 July 13
Paul,

Daniel is spot on with his answer. But I like to extend Daniel's second scenario a bit.

A command should have (or at least in IMO) a one-to-one correspondence with a use case. When you handle a request for a user (when MVC calls one of your action methods) that is always one use case; never more. So you should never execute more than one command in a action method.

Although in general, the answer would be to wrap this in an [Facade Service](https://blog.ploeh.dk/2010/02/02/RefactoringtoAggregateServices/), as Daniel says, in this case that Facade Service itself would become the use case and thus the command handler.

Command handlers, however, should not depend (directly or indirectly) on other command handlers. The `ICommandHandler<T>` abstraction should just be a thin layer between your Presentation Layer and Business Layer. This command handler can still depend on other dependencies that might do the actual work, but not on other command handlers. This flat hierarchy is easier to follow, but more importantly, nesting command handler makes it much harder to apply cross-cutting concerns, since most cross-cutting concerns should only be applied to the handler that it triggered directly from the Presentation Layer. Think about applying transactions and deadlock retry for instance. See your `ICommandHandler<T>` as your gateway to the business layer.

---
#### Paul Seabury - 03 July 13
hanks both Daniel & Steven!

Very good info that I've had a little while to digest, and in fact am implementing some code now based upon the over-injection post that Daniel directed me to.

My question still sort of remains though - what if I have a scenario, say a Message Processing Server, where they may be many commands:

* CreateNewUserCommand
* UpdateUserPrefsCommand
* DeleteUserCommand
* SendUserNotificationCommand
* ... (My imagination fails me, but there could be many more)

This could be in a controller, or just a standalone server. Now, I want all of these handlers to be available to the Server/Controller, but I still may suffer from the same over-injection disease without breaking any of the other principals. No command interdependency etc. You can imagine that in a `UserAccountController` for example, you wouldn't be breaking the SRP but still may have a lot of Commands.

BTW - To remedy my current situation I aggregated like-commands into command processors and will inject 2 of them instead of 6 handlers.

---
#### Steven - 03 July 13
This would be an unusual case for a Controller to need, but not for a Windows Service or WCF service. A Windows Service would typically be used to read from a queue with commands and execute those commands. WCF will process incoming commands.

Both services will deserialize objects from XML, JSON or some other format back into .NET classes and use and use metadata to get the actual command type and resolve the corresponding command handler. You can see an example of this [here](/p/maintainable-wcf/) (see the `CommandService` class).

But if you have multiple types of applications that need to have this same processing logic, in that case you need some kind of factory for command handlers, a `ICommandProcessor`. Something like:

```
public interface ICommandProcessor
{
    void Process(object command);
}
```

Your WCF service, and Windows Service can both call the `ICommandProcessor` and its implementation will do the reflection as shown in the linked article.

---
#### Mike - 12 July 13
This is perhaps one of the best posts I have read in a long time! It has really opened my eyes on the power of decorators and I'll have to be careful not to overuse the concept everywhere. Thank you for taking the time to write and share quality knowledge.

---
#### Benjamin - 08 January 14

Hi Steven, this is a very interesting article with some great ideas.

I was wondering how this relates to the command pattern. Would you consider this to be an implementation of the command pattern or is the name where the similarity ends?

In the standard implementation, the command objects have a uniform, no-arg execute method which makes it easy to pass the data around but most implementations I have seen end up becoming unwieldy when you start trying to add additional features such as undo (which usually add additional methods to the command classes, violating the SRP).

I can see your architecture making some of these thing much easier, for example:

```
public class RegisterUndoCommandHandlerDecorator : CommandHandler
{
    private UndoManager Manager { get; set; }
    private CommandHandler Wrapped { get; set; }
    
    public RegisterUndoCommandHandlerDecorator(
        UndoManager manager, CommandHandler wrapped)
    {
        this.Manager = manager;
        this.Wrapped = wrapped;
    }

    public void Handle(T command)
    {
        // The manager is responsible for actually determining the 'undoable'
        // command handler for a given command...
        this.Manager.Register(Command);
        this.Wrapped.Handle(Command);
    }
}
```

I would appreciate your thoughts on this.

---
#### Steven - 14 January 14
Hi Benjamin,

I don't consider this the [Command Pattern](https://en.wikipedia.org/wiki/Command_pattern), although the patterns are clearly related—both deal with commands. But they are also very different—the command pattern deals with a single `ICommand` interface that consumers can depend on. This allows them to know nothing about the commands they execute and it allows consumers to store, execute, and undo a list of unrelated commands. Take for instance a text processor or painting application where changes are made in lots of small steps and each step must be undoable. In such application it is pretty clear you need the command pattern. For Line of Business applications, however, you often deal with transactions and need to add a lot of cross-cutting concerns around those transactions. This is a clear case for the pattern described in this blog post.

---
#### Graham - 27 January 14
Hi Steven,

How would you arrange these classes in a larger VS project/solution?

Would you have the commands in one project and the handlers in another? Would you recommend having one single project just for interfaces?

I currently have a structure of:

- Context (EF mappings, DBContext etc)
- Model (concrete)
- Repositories (concrete)
- Services (concrete)
- Interfaces (repository and service interfaces)
- Core (contains useful helpers, extensions etc.)

Would you split services into commands and handlers and wire up everything using IoC in the client app?

---
#### Steven - 02 February 14
Graham,

It all depends on context. It all depends on the size of your project, the number of developers working on it, and your application requirements.

If you are sending commands over the wire for instance (using a WCF service for instance), it becomes really useful to have some sort of 'Contract' assembly that contains just the commands. This contract assembly can be shared by the server and the client, and you could even share it with third parties. This is the approach I took in a recent project.

When you don't intend to send commands over the wire, you might as well place the command and its command handler in the same file. This makes it really easy to navigate to the handler from a consumer (by pressing F12 in Visual Studio). Tools like Resharper have better code navigation support than VS and they often make it easy to navigate to the implementation without having to place the command and the handler in the same file.

When the project becomes bigger, you might again want to prevent the presentation layer(s) from taking a dependency on the business layer. In that case again the commands and the `ICommandHandler<T>` interface must be in a different assembly. In such project it could be beneficial to again have a 'Contract' assembly that contains interfaces and commands. But again, it all depends on context.

> Would you split services into commands and handlers and wire up everything using IoC in the client app?

You should certainly not replace all services by commands and handlers; services still have their place in any application and in the applications I build command handlers always depend on other services. But services like `OrderServices` and `CustomerServices` will be gone. Those are a big design smell. On the other hand, any logic that two command handlers share, should be extracted into its own service. And of course everything should written using dependency injection. Whether you need to use a DI library depends on your needs, but you'll soon find out that a DI container is a really useful tool when you use the command/handler pattern.

---
#### Ivaylo Dimov - 13 April 14
Hi Steven, the articles for commands and queries are one of the best I have red in the past year. Thank you!

My question is should one use commands to perform the standard crud operation like Save? Generally save could be an method in the repository of the aggregate root that could be called from some domain services or directly from the controller. I imagine that we could have a save command that calls the save method of the corresponding repository an probably we could create generic SaveCommandHandler that can be inherited when needed to add some specific functionality. Am I on the right track?

---
#### Steven - 13 April 14
Hi Ivaylo, it depends on what you're trying to achieve whether this is useful or not. In general I would say that the command/handler pattern is not a replacement of the repository pattern. If your command handlers contain one line of code to map to the repository it might be a useless abstraction, and it might be better to directly inject a repository in the consumer.

On the other hand, using commands for CRUD operations does allow you to have a single abstraction to deal with in case communication goes through a web service (see one of my later articles about [Highly Maintainable WCF Services](/steven/p/maintainable-wcf/) for instance). That's what we did in a precious project. We used generic `GetByIdQuery<TEntity>` query and `SaveOrUpdateCommand<TEntity>` command to simulate CRUD operations. On the client we hid those commands and queries behind a `IRepository<TEntity>` interface, but that abstraction did not exist on the server.

---
#### [DalSoft](https://www.dalsoft.co.uk/blog/) - 03 May 14
This is one of the best blog posts I've read in a long time (and the next post on queries is even better!). It pre dates posts by Rob Conery and Ayende on the same theme. I just don't know why some devs won't let the repository pattern go.

Thank you for sharing this

---
#### James - 09 September 14
Hi Steven thanks for the articles even if I am still trying to wrap my head around it all as I'm still in the Repository mindset.

One question I have regarding updating a database, where would security checks fit into this?
An example I have is a user record is being updated (multiple fields at once), some of those fields are lookups in a multi-customer system. I want to prevent a devious user from choosing a lookup (via ID) that doesn't belong to them.

The way I do this currently is to check via a validate method just before I update the database and fail if they have picked an ID from another organisation.

Would a similar check be suitable in the `MoveCustomerCommandHandler.Handle` method here? The check in my case involves a read from the database that returns a boolean (user account has permission against lookup ID for organisation, or not).

Anyway thanks for sharing.

---
#### Guy - 09 September 14
James, I am currently working on this exact requirement.

I implemented an `AuthorisationCommandHandler` as a decorator of all commands, and ascertain whether the current user (injected as `IPrincipal`) has correct permissions to execute that specific command (which are mapped to the user via their full type name). If you have specific lookups per handler (which it sounds like you do), then you should create a validator handler for that specific command handler and decorate only that specific command handler in your container binding setup. Hope that helps.

---
#### James - 09 September 14
Hi Guy

Thanks for the response, I am still new to this way of thinking and at the moment I am using Web Api and validating access to controller actions using an authorise attribute which seems to work and appears early enough in the pipeline.

I will take a look at this further though and look at how I can implement the pattern described here and if it seems like a good replacement for our increasingly complex repository pattern implementation.

I have started a thread [here](https://github.com/dotnetjunkie/solidservices/issues/4) regarding the idea of row-based security and where it fits into the pipeline, hopefully it will spawn some debate.

---
#### AndrejK - 17 November 14
Thank you Steven for great article. I have one question:
How would you evaluate whether command (represented by button in UI) is "visible" to user when some of the criteria are now split between different Handlers?
Permissions, validation, command criteria/rules... would need to be somehow combined together and evaluate before/without executing the Command pipeline.

(And solution when button always shows wouldn't work in more complex systems)

---
#### Steven - 17 November 14
Hi Andrej, it depends on your requirements, but what you can do is mark the command with an attribute that states the roles or permission that the user must have to be able to execute the command. Besides having a decorator that verifies this upon execution, and you can query this metadata in your presentation layer to make a certain button visible or not.

---
#### Wayne - 28 November 14
When wrapping command handlers with a decorator that add transactional behavior, you need to make sure that the nested handlers run in the same transaction as the outer most handler.

How would you handle this?

---
#### Steven - 29 November 14
Wayne,

The way to handle this is to prevent having nested command handlers in the first place. As I see it, that ICommandHandler abstraction is a thin layer between the business layer and the outside world; don't let the business layer itself execute commands.

In my experience this makes your code much cleaner and this prevents having the problem of those 'conditional' decorators altogether, because your transaction decorator will certainly not be the only decorator that you only want to apply to the outer command handler.

If you find yourself in a situation that you have multiple command handlers that share the same logic, either extract that logic to a [Facade Service](https://blog.ploeh.dk/2010/02/02/RefactoringtoAggregateServices/) or start publishing domain events.

---
#### AndrejK - 29 November 14
Steven (& Wayne)

Commiting transaction is not resposibility of CommandHandler. Commit is executed after Command(s) executes, so decorators or even multiple commands could be put under same transaction.

Steven, I understand the suggestion about facade service but what is your experience using inheritance?
(e.g `CommandHandler[Base]`, `CommandHandler[IBaseInterface]` would also be called when `Command: Base, IBaseInterface` is executed)

---
#### Steven - 29 November 14
Hi AndrejK,

I agree that committing the transaction is not the responsibility of the command handler. It's a cross-cutting concern and should therefore be part of your infrastructure. But since the execution of a command itself should be transactional, this cross-cutting concern should be placed in between the consumer of the handler (e.g. an MVC controller) and the actual business logic itself (the command handler implementation), In other words, the right place to do so is using a decorator, because this allows both the consumer as the command handler implementation itself to be oblivious of this cross-cutting concern.

Handling multiple commands in one transaction is something you shouldn't do IMO, because the command itself describes an action that should be atomic; if you have multiple commands that you want to execute in one transaction, you are really talking about one single command. The responsible command handler implementation for this command however, could still delegate the work to other services, and might call one service 100 times, to insert 100 records into the database.

Don't use base classes for your command handlers. If you do that, this means that there is something wrong with your design. I speak from experience here, since I used to do that in the past, but since I use decorators and follow the SOLID principles, I've never seen any good reason to use a CommandHandlerBase class again; ever.

Those base classes make your code harder to test and harder to maintain. Ask yourself why you need such base class. Do you implement cross-cutting concerns in this base class? Don't do that! Use decorators instead, because this is much more flexible and maintainable. Do you let this base class hold some dependencies (properly injected using property injection) that are used by most handler implementations? Don't do that! This hides the fact that your implementations are violating the Single Responsibility Principle, i.e. they do too much and are too complex. You might be 'solving' the problem of constructor over-injection, but you will not solve the problem of letting these classes do too much.

---
#### Joe - 21 January 15
Would it make sense to have a command with no parameters for example a command to disable an app setting?

In this case do you create a class with no methods or properties to pass to `IQueryHandler<T>`? `DisableFeatureZZZCommandHandler : IQueryHandler<DisableFeatureZZZCommand>`

The query handler will mutate database state.

---
#### Steven - 21 January 15
Hi Joe, that absolutely makes sense. It makes sense for both queries and commands, although it's a much more likely scenario for queries than commands. And although a command can be parameterless, its command handler could still use contextual information such as the user credentials, time settings, etc, to do the processing.

There's only one particular thing that is worrying me about your example, and that is that you seem to be mixing the concepts of queries and commands. You should not have a command handler that implements the query handler interface. Keep them separate. A command is for mutating the system. A query is for getting data out of the system without side effects.

---
#### Joe - 21 January 15
Is there ever a case to have the command handler return a value such as a status or unique id of a new record? How would you handle this on `ICommandHandler<T>` as your example returns void?

Do you have any posts on how you structure the domain / business logic of the command handlers? Do you write all the database update code inside of the handlers?

Finally do you abstract the libraries used for data access, such as abstracting the EF code behind an interface?

Thank you.

---
#### Joe - 21 January 15
Thanks Steven and sorry I made a typo I meant to say command handler but typed query handler.

I really like your examples here and trying if we can use it in a current project. The decorator for logging could solve the auditing requirement. Took your example and stored the command as json in a db. All the command properties are serialized to json along with the command class name. One class to log all the commands.

---
#### Steven - 21 January 15
> Is there ever a case to have the command handler return a value such as a status or unique id

Joe, please read [this article](/steven/p/data-commands(.

> Do you have any posts on how you structure the domain / business
> logic of the command handlers?

No, I'm sorry. Nothing on that.

> Do you write all the database update code inside of the handlers?

It depends on the application. Sometimes I publish events and have asynchronous event handlers process parts of the business logic.

> Finally do you abstract the libraries used for data access,
> such as abstracting the EF code behind an interface?

That depends on the application.

I like to invite you to ask any questions about implementing these patterns on [this forum](https://github.com/dotnetjunkie/solidservices/issues). My weblog is not suited for this type of Q/A.

---
#### Matt - 26 March 15
I'm struggling a bit understanding if my vision for the system I'm working on is going to be heading toward a maintainability problem.

In the past, I've implemented controllers (MVC or WebAPI, doesn't matter for this discussion) centered around the logical entity; Product, Order, User - you get the idea.

The problem enters as the number of commands a given entity supports grows, the constructor injection method seems to break down. Paul Seabury already touched on this, but the linked suggestion seemingly punts the issue to a different interface that would wrap those dependencies.

For example:

```
public ProductController
{
    private ICommandHandler<DeactivateProductCommand> mDeactivate;
    private ICommandHandler<PublishProductCommand> mPublish;
    ...repeat
    private ICommandHandler<DeleteProductCommand> mDelete;

    public ProductController(
        ICommandHandler<DeactivateProductCommand> aDeactivate,
        ICommandHandler<PublishProductCommand> aPublish,
        ...repeat
        ICommandHandler<DeleteProductCommand> aDelete) {
        //...surely you see where I'm going
    }
}
```

The [refactoring](https://blog.ploeh.dk/2010/02/02/RefactoringtoAggregateServices/) mentioned purporting to solve this seems a bit like it just punts the issue into a new class.

I'm sure there is a point that I don't see here; maybe introducing aggregate services allows you to only inject the aggregates you need for the actions you're testing?

---
#### Steven - 26 March 15
Hi Matt,

I think I touched this subject a bit in [this article](/steven/p/queries/), but focusing classes around a single entity leads to severe violations of the Single Responsibility Principle (as Daniel Hilgarth responded to Paul Seabury's question). Those classes will get big and unmaintainable and I think your example shows this very clearly.

Falling back to facade services will not help at all in this case, because it doesn't resolve the SRP violation.

The real solution is to focus your controllers around use cases, just as command handlers do. So instead of having a `ProductController`, think about having a `DeactivateProductController`, `PublishProductController` and `DeleteProductController`. This is what I do in the applications I write. This keeps controllers really small and focused.

---
#### Jonas - 04 May 15
Hi Steven,

thx for a great post. I am thinking of using your approach in a project, but am a bit concerned of testability.

Im interested in hearing your opinion regarding integration testing. Since we have now separated concerns into lets say a command and a commandvalidator class which decorates the command, how would you ensure that validation is performed before your command is executed? The 2 classes can be unit tested, but as the responsibility of the coupling is now tied to the DI-framework, I cannot see how the interation test can be done.

What if someone accidently alters my (now much more complex) DI-code so that my command is no longer decorated with the validator? I would argue that moving responsibility to the DI-code makes it harder to do integration testing. Obviously someone will always be able to modify the code and then cause an error. In this case, using a traditional businesslayer method I would normally write an integration test, that ensured that every time I call the command, validation is performed prior to performing the action.

Looking forward to hearing your opinion.

---
#### Steven - 04 May 15
Hi Jonas,

When doing integration testing, it is quite usual to involve the container into the integration tests. This is quite obvious, because you want to test the whole integration chain in the application, which might mean you touch several layers, and a multitude of classes.

Still, I would prefer testing both the command handler and its validator(s) each in isolation, preferably in a unit test, because this results in tests that are much more readable, trustworthy and maintainable. During unit testing you should not use the container at all, but test one single class in isolation.

In the same way you would have a few unit tests that verifies whether the command handler decorator that executes the validators works as expected. You should check if that decorator executes all validators, executes them before the decoratee, and ensures that the decoratee isn’t called in case of a validation error.

What’s left is a rather straightforward integration test that checks whether the DI configuration would pick up validators at all, and if they are injected correctly into the command handler decorator.

As an extra check, you could search the code base for classes that contain a “Validate(X)” method, where X is some command class, and where the given class does not implement `IValidator<T>`. This might be useful, because forgetting to implement the `IValidator<T>` interface on a validator class will cause the container to skip its registration, while the code compiles and your unit tests will pass.

---
#### Steven - 26 May 15
Daniel Whittaker has a nice post where he explains the difference between the Gang of Four Command pattern and the pattern as described in [this article](https://danielwhittaker.me/2015/05/25/is-a-cqrs-command-gof-command/).

---
#### [Brent](http://www.ariasamp.net/) - 19 January 16
Suppose that command validation rules are complex, and perhaps even dynamic. If I build a multi-tenant application, the validation rules might be different for each tenant. I don't think I want one `ValidationCommandHandlerDecorator` for each rule. I probably want one `ValidationCommandHandlerDecorator` that is capable of addressing all the (dynamic) validation rules simultaneously, presumably through internal composition.

Is that the approach you would take?

Incidentally, a little off-topic, is there a simple rules-engine that you've been reasonably pleased with that can handle the dynamic scenario I'm describing? What is it?

---
#### Steven - 19 January 16
Hi Brent,

When it comes to validating, it is really useful to have an `IValidator<T>` as abstraction for the validation of a single element (`T`). If you do this, it becomes trivial to have validator implementations that are specific to a tenant.

If you give each Tenant its own app domain (own IIS site), you can place tenant specific validators in a seperate assembly that is loaded during startup.

If, on the other hand, all tenants run in the same site and in the same app domain, you can mark validators with an attribute (or use convention over configuration) and have either a decorator or a composite validator that is able to filter out validators that don't belong to the currently active tenant.

You can read [this](https://github.com/dotnetjunkie/solidservices/issues/4) to get more ideas.

---
#### Denis - 11 July 16
Steven which O/RM suites better for CQRS architecture (both for command and query side) and which one you usually use to your applications?

---
#### Steven - 11 July 16
Hi Denis,

I usually use Entity Framework, but I think you can use any ORM tool. Nice thing is that because of the separation between commands and queries, you can even select a different tool for each side. I use EF for both sides.

---
#### Gavin - 25 August 16
Fantastic article. Nearly 5 years old and still completely relevant.

For scenarios with complex business logic, e.g. when you need to update, and add to, multiple db tables in a single Web API HTTP post request, would an facade service be the best place for the numerous, atomic commands that need to be run?

E.g. a `SignUpNewCustomer` service that encapsulates:

1. `AddCustomerCommand`
2. `AddCustomerPaymentMethodCommand`
3. `AddCustomerSubscription`

If feels like this is SRP violation but I don't see a clear alternative. The only possible exception being to have a web api call for each of the three commands. This would obviously be slower & introduce some latency into the application. I'd love to hear your thoughts.

Also, have you read or have any opinions on [Rob Conery's take on CQRS](https://rob.conery.io/2014/03/03/repositories-and-unitofwork-are-not-a-good-idea/).

---
#### Steven - 25 August 16
Hi Gavin,

> would an aggregate service be the best place for the numerous, atomic commands that need to be run?

In my view of the world, the command should by itself be the business transaction and should be atomic. In other words, your command should be `SignUpNewCustomerCommand` with its related `SignUpNewCustomerCommandHandler`. The `SignUpNewCustomerCommand` is what you process in a single Web API request.

In case the handler has shared logic (logic that is used by other handlers as well), you can extract this logic to an facade service, like an `ICustomerAdditionService`. If you have a lot of this shared logic, it might be beneficial to add a common abstraction for this type of logic. For instance, you might define an `ILogicCommandHandler<TLogicCommand>` and create `AddCustomerLogicCommand`, `AddCustomerSubscriptionLogicCommand`, etc. This separates the main use case (`SignUpNewCustomerCommand` from the reusable building blocks). In other words, you make your abstractions [holistic](http://scrapbook.qujck.com/holistic-abstractions-take-2/). This makes boundaries for adding cross-cutting concerns very clear, since you often only want to add transaction handling, deadlock retry, security and authorization checks only at the outer layer.

---
#### Luc - 13 July 17
Hi Steven. Great article! I had a Command architecture which after adding features, those classes were growing with too many responsibilites. I was looking some kind of design like yours so after googling I ended up here.

But I'm thinking it with a slighlty different approach. Instead of Decorator pattern which chains the handlers, I'm thinking in an independent list of handlers and an object (could also be a command handler) that knows the workflow of the execution. For instance, the most common workflow would be Authorize -> Validate -> Execute -> Log

Other workflow could be Authorize -> Validate -> Asyn Execute -> Log

If you encapsulate this wiring knowledge in an object you shouldn't be repeating the wiring code, and also a CommandFactory could be using it to build a command given the command name. if, for instance, in a future I want to add a transaction number generator to some commands, i just create another workflow builder handler that add this generator handler after the executing handler, without having to change the wiring code in all the controllers (or in the factory).

What do you think about this approach?

(Besides that, I'm wondering if is it really ok to decorate handlers, because actually you aren't adding features to handlers, instead we should add features and concerns to the *execution*)

---
#### Steven - 04 July 17
Luc,

I'd have to see some conceptual code to see what your design is. To me however, cross-cutting concerns are not work flow. Work flow is about business-related concerns, while authorization, validation and execution are technical concerns. On top of that, having factories for commands and builders for workflows seems like am overkill and a lot of extra complexity.

But again, without some actual code, it's pretty hard to argue about this. If you create a new question with some code [here](https://github.com/dotnetjunkie/solidservices/issues/new), we could discuss this a bit more.

---
#### gizero - 01 September 18
I'm having a hard time accepting that commands should not return values. Here's an example from a project I am working on now: in a REST API I want to generate an authentication token (JWT). In the REST controller I would like to execute a command to have the token generated in my business layer. Would you agree that this can be considered a command? If not, what is it? I'm not requesting something that already exists, so it's not a query. I need to generate the token based on the user's identity and then return the generated token. Lets say I also want to update a flag in the database that holds the last authentication time for a user. In fact, I probably want to generate what's called a refresh token at the same time, so that an expired token can be renewed. This command both mutates a state and returns a value. What's so wrong with that? How would the client get hold of the generated token if the command cannot return a value? The only solution I can think of would be to split this operation into two: one `GenerateTokenCommand` and one `GetUserTokenQuery`. A user can have multiple valid tokens at the same time (authenticated on multiple computers) so I would have to add logic to figure out which token to return from the `GetUserTokenQuery` query. This also just seems like a completely unnecessary sequence, instead of just returning the generated token from the `GenerateTokenCommand`. Any insights would be highly appreciated.

One more thing: I have seen in your examples that you inject your query and command handlers directly into your controllers. In my current design I instead have a traditional Service class (`TokenService` in this example) that exposes several methods, like `GenerateToken()` and `RefreshToken()`. I therefore inject the query and command handlers in the Service classes and only inject the Service classes into the controller. Do you consider the Service class an unnecessary layer? I'm thinking I want to add some shared business logic in this Service class that doesn't belong in the command/query itself and that cannot be added by decorating the command/query.

---
#### Steven - 01 September 18
Hi Gizero,

> I'm having a hard time accepting that commands should not return values.

Commands should not return a value. This is something I still strongly believe in. This can in most cases be achieved by letting the client supply the value to the server, instead of letting the server return some computed value. This, however, does mean you will have to start working with globally unique identifiers (GUIDs).

In the case of your JWT tokens, for instance, the client can be made responsible for the creation of a valid token, after which it can supply it to the server, while requesting its logon.

The rule that commands don't return data, however, doesn't mean that your REST API is required to do the same. Your API can happily return a value for something your command cannot. For instance, validation errors can happily be returned, even though the command might have through a `ValidationException` in that case.

Still, there might be corner cases. I experienced those too often be centralized around logon process of an application, even though, I think that in many cases it can still be prevented.

But for those exceptional scenarios, you might still be able to internally use commands, but at the API layer you might be forced to create some custom logic. This can happen, for instance, when it is not appropriate to let the client generate an ID. In that case, you can do that as part of your Web API Facade. For instance:

```
// Web API Action method
public Token LogOn(string name, string pwd) {
    Guid id = Guid.NewGuid();
    this.logOnHandler.Handle(new LogOn(name, pwd, id));
    return this.getTokenHandler.Handle(new GetToken(id));
}
```

Or, in such corner case, you can decide to circumvent commands and queries altogether, and instead use an old-fashioned service class. Which brings me to your next question.

> Do you consider the Service class an unnecessary layer?

Yes, I do. Since I'm using this architecture, there is almost never a reason (except: see above exception) to create those Service classes any longer. The Command Handler has become the new Service. Even better, when you're building a Web API, I also prefer even stripping out controllers, since they become empty shells that only forward the operation. You can find more information about this, [here](https://github.com/dotnetjunkie/solidservices/).

---
#### gizero - 01 September 18
Hi,

No, the client cannot generate the JWT token and supply it to the server; the JWT token is a security token that must be signed by the server, since it has the secret key.

And I don't see any argument why it is wrong to just return the generated token from the command. Sure, I can call one command to generate the token and one to query it, inside the token endpoint in the controller, but I don't see any benefit from it.

Skipping controllers when you're creating a REST API makes no sense to me, they are the REST API. I can't have a Angular client call a Command Handler directly...

---
#### Steven - 14 September 18
Hi Gizero,

It isn’t wrong, per see, to return data from commands. I used to do this myself, as you can read [here](/steven/p/data-commands). However, I found that disallowing the return of data from commands in general is an improvement, since it simplifies the command handlers, and makes it easier to make command handlers idempotent. Idempotency allows commands to be queued, resent, and retried, without causing unfortunate actions caused by commands executed twice.

The result of such design decision is, of course, that you need to ‘work around’ cases where there is no alternative for returning data, which might very well be the case in your JWT example. Although you can chose to change your architecture and allow all commands return a value to accommodate this, I found this to be less ideal. I found that the need to return data is so rare in the applications I build, that I rather work around the few cases that do need to return data, instead of changing my architecture and complicating all command handlers to accommodate the few.

The thing about architecture is, though, that it’s always about striking a balance. You will have to find the architecture that strikes the most optimal situation in your particular application. For me, this is the rule that commands can’t return any data. This might be different in your case. For instance when you’re dealing with an already existing application that works with auto-increment database identifiers, rather than GUIDs, it might be easier to let commands return data.

What I meant by skipping the controllers was not that the client would directly connect to the handlers. This will obviously not work, because you always need a piece of server infrastructure. But because writing controllers, however, is repetitive and error prone, there is much sense in trying to remove that layer althogether.

You can do so by creating a single piece of middleware that dispatches an incoming request to the right command handler. This is what [the referenced sample project](https://github.com/dotnetjunkie/solidservices/) does.
