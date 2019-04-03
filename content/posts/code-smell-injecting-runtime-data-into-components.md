---
title:   "Dependency Injection Code Smell: Injecting runtime data into components"
date:    2015-11-07
author:  Steven van Deursen
tags:    [Dependency Injection, OOP, Software Design]
draft:   false
aliases:
    - /p/runtime-data
---

### Injecting runtime data into your application components is a code smell. Runtime data should flow through the method calls of already-constructed object graphs.

A recurring theme when it comes to questions about dependency injection is how to wire up and resolve components a.k.a. [injectables](http://misko.hevery.com/2008/09/30/to-new-or-not-to-new/) (the classes that contain the application's behavior) that require runtime data during construction. My answer to this is always the same:

> **Don't inject runtime data into application components during construction—it causes ambiguity, complicates the Composition Root with an extra responsibility, and makes it extraordinarily hard to verify the correctness of your DI configuration. Instead, let runtime data flow through the method calls of constructed object graphs.**

Here's an example of a `MoveCustomerCommand` component that gets constructed with runtime data—the `CustomerId` and `DestinationAddress`.

{{< highlight csharp >}}
interface ICommand
{
    void Execute();
}

class MoveCustomerCommand : ICommand
{
    private readonly ICustomerRepository repository;

    public MoveCustomerCommand(ICustomerRepository repository)
    {
        this.repository = repository;
    }

    public int CustomerId { get; set; }
    public Address DestinationAddress { get; set; }

    public void Execute()
    {
        // use repository, Id and Address to handle the operation
    }
}
{{< / highlight >}}

In the code snippet, the construction of the component requires both the `ICustomerRepository` dependency in its constructor and the runtime data values for the customer ID and address through its public fields. The runtime values are specific to one particular request.

This implementation is problematic because you need request-specific information to correctly initialize this component. To be able to create a new `MoveCustomerCommand`, the consuming code must either create the component itself, delegate its creation to a factory, or call back into the container passing the runtime data—all of which cause problems of their own:

* Creating the component in code is a [Dependency Inversion Principle](https://en.wikipedia.org/wiki/Dependency_inversion_principle) violation and makes it impossible to decorate, intercept or replace the component without making sweeping changes throughout the code base.
* A factory will add a [pointless extra layer of abstraction](/steven/p/abstract-factories/) to the application, increasing complexity and decreasing maintainability. Complexity is increased because the consumer now has to deal with an extra abstraction (the factory). Maintainability is decreased, because for each component, a factory method must be created and maintained that will handwire the component with its dependencies.
* Calling back into the container directly leads to the [Service Locator anti-pattern](https://blog.ploeh.dk/2010/02/03/ServiceLocatorisanAnti-Pattern/).

Both the factory and Service Locator approach cause the creation of this part of the object graph to be delayed until runtime. Although delaying the creation of the object graph until runtime isn't a bad thing per se, it makes it harder to verify your configuration because resolving the root object will only test some of the object graph.

The solution to these issues is actually quite simple: remove the injection of runtime data out of the construction phase of the component and pass it on using method calls after construction. [Not surprisingly](/steven/p/commands/), the following design solves these problems:

{{< highlight csharp >}}
interface ICommandHandler<TCommand>
{ 
    void Handle(TCommand command); 
}

class MoveCustomerCommand
{
    public int CustomerId { get; set; }
    public Address DestinationAddress { get; set; }
}

class MoveCustomerHandler : ICommandHandler<MoveCustomerCommand>
{
    private readonly ICustomerRepository repository;

    public MoveCustomerHandler(ICustomerRepository repository)
    {
        this.repository = repository;
    }

    public void Handle(MoveCustomerCommand command)
    {
        // use repository and command to handle the operation
    }
}
{{< / highlight >}}

The command has now become a behaviorless [Parameter Object](https://refactoring.com/catalog/introduceParameterObject.html) that can be passed on to the new command handler component. This change solves the problems with the original design:

* The creation of object graphs can now be verified with a single automated test.
* No callbacks to a Service Locator are needed.
* No factory is needed; code can depend directly on `ICommandHandler<MoveCustomerCommand>`.
* Creation of the object graph is not needlessly delayed until runtime.

The general fix here is to change the public API to expose the runtime data through its contract so that the request-specific information can be passed through. This allows the component to become stateless.

But not all violations can be solved like this. Sometimes you don't want to change the public API of your abstractions, especially when the runtime data is an implementation detail. To visualize this point let's take a look at the following example:

{{< highlight csharp >}}
class CustomerRepository : ICustomerRepository
{
    private readonly IUnitOfWork uow;
    private readonly int currentUserId;
    private readonly DateTime now;

    public CustomerRepository(IUnitOfWork uow, int currentUserId, DateTime now)
    {
        if (currentUserId <= 0) throw new ArgumentException();
        if (now.Year < 2015) throw new ArgumentException();
        
        this.uow = uow;
        this.currentUserId = currentUserId;
        this.now = now;
    }
    
    public void Save(Customer entity)
    {
        entity.CreatedBy = this.currentUserId;
        entity.CreatedOn = this.now;
        this.uow.Save(entity);
    }
}
{{< / highlight >}}

The example shows a `CustomerRepository` that in addition to depending on an `IUnitOfWork`, also requires the current user id and the current system time. The current user id is the `Id` of the logged in user on whose behalf the operation is executed. This `Id` and current time are both used to update the `Customer` entity before it is persisted to the database.

Just as in the previous example, this use of runtime data is problematic. In this component there is some ambiguity in the constructor because when examining the type, it is unclear what is needed to inject. What `DateTime` value should be injected? Should it be the `Now`, `Today`, yesterday? In other words, it would be very easy to create the `CustomerRepository` with incorrect values, and the only way to verify whether the configuration is correct is through manual testing or a rather awkward integration test.

In this example, however, you don't want to make the runtime data into input parameters of the `CustomerRepository`'s `Save` method because that would mean the `Save` method gets two extra parameters. The addition of these parameters to the `Save` method will ripple through the system because the direct and indirect consumers of the `ICustomerRepository` abstraction will need to add these parameters to their API as well—all the way up the chain. Not only would this pollute the API, it would also force you to make sweeping changes throughout the code base for each and every piece of runtime data that some implementation requires in the future.

When a component requires runtime state in its constructor, it becomes impossible to verify the configuration in a maintainable way. A unit test must be written for each component that verifies whether that particular object can be created, while supplied with fake—but valid—runtime data needed for the component to initialize.

The current user id and current time are runtime values but they are implementation details and consumers of the repository should not be concerned with such details. You should place these runtime values behind clearly defined abstractions, removing the ambiguity in their definition and allowing the runtime data to flow through the system with the method calls, as shown in the following listing:

{{< highlight csharp >}}
class CustomerRepository : ICustomerRepository
{
    private readonly IUnitOfWork uow;
    private readonly IUserContext userContext;
    private readonly ITimeProvider timeProvider;

    public CustomerRepository(
        IUnitOfWork uow, 
        IUserContext userContext,
        ITimeProvider timeProvider)
    {
        this.uow = uow;
        this.userContext = userContext;
        this.timeProvider = timeProvider;
    }
    
    public void Save(Customer entity)
    {
        entity.CreatedBy = this.userContext.CurrentUserId;
        entity.CreatedOn = this.timeProvider.Now;
        this.uow.Save(entity);
    }
}
{{< / highlight >}}

Creating implementations for the two newly defined abstractions could be as simple as the following:

{{< highlight csharp >}}
class TimeProvider : ITimeProvider 
{
    public DateTime Now => DateTime.Now;
}

class HttpSessionUserContext : IUserContext 
{
    public int CurrentUserId => (int)HttpContext.Current.Session["userId"];
}
{{< / highlight >}}

These two implementations are adapters; they adapt your application-specific abstractions to a specific technology, tool, or system component that you wish to hide from your application components. These adapters are part of the [Composition Root](https://freecontent.manning.com/dependency-injection-in-net-2nd-edition-understanding-the-composition-root/).

Do note though that primitive values (such as `int` and `string`) are not runtime data per definition. Configuration values, such as connection strings, are primitives, but they are usually known at application startup, and don't change during the lifetime of the application. Those 'static' values can safely be injected into the constructor. Still, if you find yourself injecting the same configuration value into many different components you are missing an abstraction, but that's a discussion for another day.

To summarize, the solution to the problem of injecting runtime data into components is to let runtime data flow through method calls on an initialized object graph by either:

1. passing runtime data through method calls of the API
   or
2. retrieving runtime data from specific abstractions that allow resolving runtime data. 

Happy injecting.

## Comments

---
#### [Dennis van der Stelt](http://dennis.bloggingabout.net/) - 09 November 15

Great article! Welcome back to blogging! :)

---
#### Jan Hartmann - 09 November 15

As Dennis stated; long awaited blog post from you and as always, on point. :-)

Hoping to see more in the future.

---
#### Nazaret - 17 November 15

Awesome! Welcome back! :)

---
#### [Yacoub Massad](http://yacoubsoftware.blogspot.com/) - 22 November 15

Great article. But I think there is something that is still missing. Consider the example of a UI application that allows people to type in an FTP server and some other connectivity settings at runtime, and then connects to such server to do some work (like allowing the user to upload or download files). Wouldn't you in this case create an abstract factory that creates some `IFtpServer` implementation given the connection settings? Consuming classes of `IFtpServer` wouldn't want to know anything about the connection settings, so you can't put these settings as method parameters. Also you cannot obtain such information from some context interface as you did with the `ITimeProvider` for example. So it seems that the need for abstract factories still exists for some cases

---
#### Steven - 14 August 16

Yacoub,

In my [latest article](/steven/p/abstract-factories/) I go into more details about why I think Abstract Factories are a design smell and should be avoided in most cases.

---
#### [Luke Briner](http://lukieb.blogspot.co.uk/) - 15 February 18

Thanks so much for this. Helping me to understand some of the more subtle issues with DI and in a very concise way while not skipping the "why not to do it this way"!
